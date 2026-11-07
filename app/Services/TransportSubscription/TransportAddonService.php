<?php

declare(strict_types=1);

namespace App\Services\TransportSubscription;

use App\Models\User;
use App\Services\Administrator\AdministratorAuditService;
use App\Services\Administrator\AdministratorPortalAccessService;
use App\Services\Transport\TransportAccessService;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class TransportAddonService
{
    public function __construct(
        private AdministratorPortalAccessService $platform,
        private AdministratorAuditService $audit,
        private TransportAccessService $schoolAccess,
    ) {}

    /**
     * Platform attestation of already checked commercial evidence, not payment verification.
     * Transport operational services independently enforce commercial and custody scope.
     */
    public function issue(User $authenticated, string $schoolId, array $input): array
    {
        abort_unless(Str::isUuid($schoolId), 404);
        $schoolId = strtolower($schoolId);
        $allowed = ['request_id', 'expected_version', 'starts_at', 'ends_at',
            'commercial_timezone', 'evidence_reference', 'reason'];
        foreach (array_diff(array_keys($input), $allowed) as $key) {
            throw ValidationException::withMessages([$key => ['This field is prohibited.']]);
        }
        $data = Validator::make($input, [
            'request_id' => ['required', 'uuid'],
            'expected_version' => ['required', 'string', 'regex:/\A[a-f0-9]{64}\z/'],
            'starts_at' => ['required', 'date_format:Y-m-d\TH:i:sP'],
            'ends_at' => ['required', 'date_format:Y-m-d\TH:i:sP', 'after:starts_at'],
            'commercial_timezone' => ['required', 'timezone'],
            'evidence_reference' => ['required', 'string', 'max:100'],
            'reason' => ['required', 'string', 'max:1000'],
        ])->validate();
        $data['request_id'] = strtolower($data['request_id']);
        $data['evidence_reference'] = strtoupper(trim($data['evidence_reference']));
        $data['reason'] = trim($data['reason']);
        if ($data['evidence_reference'] === '' || mb_strlen($data['reason']) < 10) {
            throw ValidationException::withMessages(['reason' => ['A reference and meaningful reason are required.']]);
        }
        $start = CarbonImmutable::parse($data['starts_at'])->utc();
        $end = CarbonImmutable::parse($data['ends_at'])->utc();
        abort_if($end->greaterThan($start->addDays(366)), 422, 'A period may cover at most 366 days.');
        $data['starts_at'] = $start->toIso8601String();
        $data['ends_at'] = $end->toIso8601String();
        $fingerprint = hash('sha256', json_encode([
            $schoolId, $data['request_id'], $data['starts_at'], $data['ends_at'],
            $data['commercial_timezone'], $data['evidence_reference'], $data['reason'],
        ], JSON_THROW_ON_ERROR));

        try {
            return DB::transaction(function () use ($authenticated, $schoolId, $data, $start, $fingerprint): array {
                $school = DB::table('schools')->where('id', $schoolId)
                    ->where('is_deleted', false)->lockForUpdate()->first();
                // Re-read eligibility and role membership; never trust a stale supplied model.
                $actor = User::withoutGlobalScopes()->whereKey($authenticated->getKey())
                    ->sharedLock()->first();
                abort_unless($actor !== null, 403);
                $this->platform->requirePlatform($actor, 'manage_transport_subscriptions');
                abort_unless($school !== null, 404);

                $existing = DB::table('transport_addon_periods')->where('school_id', $schoolId)
                    ->where('request_id', $data['request_id'])->first();
                if ($existing !== null) {
                    abort_unless(hash_equals($existing->request_fingerprint, $fingerprint), 409,
                        'An activation request cannot be reused for different evidence.');

                    return $this->issueResponse($existing, true);
                }
                abort_unless((bool) $school->active
                    && in_array($school->lifecycle_state, ['active', 'grace', 'read_only', 'trial'], true), 403);
                abort_if($start->lessThan(CarbonImmutable::now('UTC')), 422,
                    'New activation cannot backdate access. Retry with a current or future start.');
                abort_unless(hash_equals($this->version($schoolId), $data['expected_version']), 409,
                    'Subscription history changed; refresh before activation.');

                $previous = $this->latest($schoolId);
                if ($previous !== null) {
                    abort_if($start->lessThan(CarbonImmutable::parse($previous->ends_at)), 409,
                        'Renewal overlaps an existing period.');
                }
                $id = (string) Str::uuid();
                DB::table('transport_addon_periods')->insert([
                    'id' => $id, 'school_id' => $schoolId, 'request_id' => $data['request_id'],
                    'previous_id' => $previous->id ?? null,
                    'starts_at' => $data['starts_at'], 'ends_at' => $data['ends_at'],
                    'commercial_timezone' => $data['commercial_timezone'],
                    'evidence_source' => 'platform_manual_attestation',
                    'evidence_reference' => $data['evidence_reference'], 'reason' => $data['reason'],
                    'request_fingerprint' => $fingerprint, 'activated_by' => $actor->getKey(),
                ]);
                $this->audit->record($actor,
                    $previous === null ? 'transport_addon_activate' : 'transport_addon_renew',
                    'transport_addon_periods', $id, [], [
                        'starts_at' => $data['starts_at'], 'ends_at' => $data['ends_at'],
                        'evidence_source' => 'platform_manual_attestation',
                        'evidence_reference' => $data['evidence_reference'],
                        'reason' => $data['reason'],
                    ], $schoolId);

                return $this->issueResponse(DB::table('transport_addon_periods')->where('id', $id)->firstOrFail(), false);
            }, 3);
        } catch (QueryException $exception) {
            $state = $exception->errorInfo[0] ?? null;
            if (in_array($state, ['23505', '23P01'], true)) {
                abort(409, 'Conflicting entitlement period, request or commercial reference.');
            }
            if ($state === '23514') {
                abort(422, 'Invalid entitlement evidence or actor.');
            }
            throw $exception;
        }
    }

    public function platformStatus(User $authenticated, string $schoolId): array
    {
        abort_unless(Str::isUuid($schoolId), 404);
        $schoolId = strtolower($schoolId);

        return DB::transaction(function () use ($authenticated, $schoolId): array {
            $actor = User::withoutGlobalScopes()->whereKey($authenticated->getKey())->first();
            abort_unless($actor !== null, 403);
            $this->platform->requirePlatform($actor, 'view_transport_subscriptions');
            abort_unless(DB::table('schools')->where('id', $schoolId)
                ->where('is_deleted', false)->sharedLock()->first() !== null, 404);

            return $this->status($schoolId);
        });
    }

    public function schoolStatus(User $authenticated): array
    {
        return DB::transaction(function () use ($authenticated): array {
            $actor = $this->schoolAccess->actor($authenticated);
            $this->schoolAccess->requirePermission($actor, 'view_own_transport_subscription');
            $schoolId = (string) $actor->school_id;
            DB::table('schools')->where('id', $schoolId)->sharedLock()->first();

            return $this->status($schoolId);
        });
    }

    private function status(string $schoolId): array
    {
        $now = CarbonImmutable::now('UTC')->toIso8601String();
        $current = DB::table('transport_addon_periods')->where('school_id', $schoolId)
            ->where('starts_at', '<=', $now)->where('ends_at', '>', $now)->first();
        $next = DB::table('transport_addon_periods')->where('school_id', $schoolId)
            ->where('starts_at', '>', $now)->orderBy('starts_at')->first();

        return [
            'school_id' => $schoolId, 'product' => 'transport',
            'period_active' => $current !== null,
            'current_period' => $current === null ? null : $this->safePeriod($current),
            'next_period' => $next === null ? null : $this->safePeriod($next),
            'version' => $this->version($schoolId),
            'operational_enforcement' => 'enforced_with_scoped_continuation',
        ];
    }

    private function latest(string $schoolId): ?object
    {
        return DB::table('transport_addon_periods')->where('school_id', $schoolId)
            ->orderByDesc('ends_at')->orderByDesc('id')->first();
    }

    private function version(string $schoolId): string
    {
        // Append-only rows; count and latest identity change on every successful issue.
        $count = DB::table('transport_addon_periods')->where('school_id', $schoolId)->count();
        $latest = $this->latest($schoolId);

        return hash('sha256', 'transport-addon:'.$schoolId.':'.$count.':'.($latest->id ?? 'none'));
    }

    private function safePeriod(object $row): array
    {
        return ['id' => (string) $row->id, 'starts_at' => $row->starts_at,
            'ends_at' => $row->ends_at, 'commercial_timezone' => $row->commercial_timezone];
    }

    private function issueResponse(object $row, bool $replayed): array
    {
        return ['period' => $this->safePeriod($row), 'request_id' => (string) $row->request_id,
            'previous_id' => $row->previous_id, 'replayed' => $replayed,
            'evidence_source' => $row->evidence_source, 'evidence_reference' => $row->evidence_reference,
            'activated_by' => (string) $row->activated_by, 'recorded_at' => $row->recorded_at,
            'version' => $this->version((string) $row->school_id)];
    }
}
