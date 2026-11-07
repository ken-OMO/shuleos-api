<?php

declare(strict_types=1);

namespace App\Services\Transport;

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class TransportHandoverAuthorizationService
{
    public function __construct(
        private TransportAccessService $access,
        private TransportAuditService $audit,
    ) {}

    public function create(User $authenticated, array $input): array
    {
        $this->rejectUnknown($input, [
            'assignment_id', 'kind', 'learner_id', 'stop_id',
            'guardian_id', 'guardian_link_id', 'staff_user_id',
            'valid_from', 'valid_to', 'reason',
        ]);

        $data = Validator::make($input, [
            'assignment_id' => ['required', 'uuid'],
            'kind' => ['required', 'in:guardian_recipient,school_receiver,school_releaser'],
            'learner_id' => ['nullable', 'uuid'],
            'stop_id' => ['nullable', 'uuid'],
            'guardian_id' => ['nullable', 'uuid'],
            'guardian_link_id' => ['nullable', 'uuid'],
            'staff_user_id' => ['nullable', 'uuid'],
            'valid_from' => ['required', 'date_format:Y-m-d'],
            'valid_to' => ['required', 'date_format:Y-m-d', 'after_or_equal:valid_from'],
            'reason' => ['required', 'string', 'max:1000'],
        ])->validate();

        $data['reason'] = trim($data['reason']);
        if ($data['reason'] === '') {
            throw ValidationException::withMessages(['reason' => ['A reason is required.']]);
        }

        $guardian = $data['kind'] === 'guardian_recipient';
        $required = $guardian
            ? ['learner_id', 'stop_id', 'guardian_id', 'guardian_link_id']
            : ['staff_user_id'];
        $prohibited = $guardian
            ? ['staff_user_id']
            : ['learner_id', 'stop_id', 'guardian_id', 'guardian_link_id'];

        foreach ($required as $key) {
            if (empty($data[$key])) {
                throw ValidationException::withMessages([$key => ['This field is required.']]);
            }
        }
        foreach ($prohibited as $key) {
            if (array_key_exists($key, $input)) {
                throw ValidationException::withMessages([$key => ['This field is prohibited.']]);
            }
        }

        try {
            return DB::transaction(function () use ($authenticated, $data, $guardian): array {
                $actor = $this->access->actor($authenticated, true);
                $this->access->requirePermission($actor, $this->permission($data['kind']));
                $school = (string) $actor->school_id;

                $assignment = DB::table('transport_dated_assignments')
                    ->where('school_id', $school)->where('id', $data['assignment_id'])
                    ->lockForUpdate()->first();
                abort_unless($assignment !== null, 404);

                if ($guardian) {
                    foreach ([
                        'learners' => 'learner_id',
                        'transport_route_stops' => 'stop_id',
                        'parents' => 'guardian_id',
                    ] as $table => $key) {
                        abort_unless(DB::table($table)->where('school_id', $school)
                            ->where('id', $data[$key])->exists(), 404);
                    }

                    abort_unless(DB::table('learner_parents')
                        ->where('id', $data['guardian_link_id'])
                        ->where('learner_id', $data['learner_id'])
                        ->where('parent_id', $data['guardian_id'])->exists(), 404);
                } else {
                    abort_unless(DB::table('users')->where('school_id', $school)
                        ->where('id', $data['staff_user_id'])->exists(), 404);
                }

                $id = (string) Str::uuid();
                DB::table('transport_handover_authorizations')->insert([
                    ...$data,
                    'id' => $id,
                    'school_id' => $school,
                    'authorized_by' => $actor->getKey(),
                ]);

                $row = $this->find($school, $id);
                $result = $this->response($row);
                $this->audit->record(
                    $actor, 'authorize', 'transport_handover_authorizations', $id,
                    [], $result
                );

                return $result;
            });
        } catch (QueryException $exception) {
            $this->translate($exception);
        }
    }

    public function revoke(User $authenticated, string $id, array $input): array
    {
        $this->rejectUnknown($input, ['expected_version', 'reason']);
        $data = Validator::make($input, [
            'expected_version' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/'],
            'reason' => ['required', 'string', 'max:1000'],
        ])->validate();
        $data['reason'] = trim($data['reason']);
        if ($data['reason'] === '') {
            throw ValidationException::withMessages(['reason' => ['A reason is required.']]);
        }

        try {
            return DB::transaction(function () use ($authenticated, $id, $data): array {
                $actor = $this->access->actor($authenticated, true);
                $row = DB::table('transport_handover_authorizations')
                    ->where('school_id', $actor->school_id)->where('id', $id)
                    ->lockForUpdate()->first();
                abort_unless($row !== null, 404);
                $this->access->requirePermission($actor, $this->permission($row->kind));
                $before = $this->response($row);
                abort_unless(hash_equals($before['version'], $data['expected_version']), 409);

                if ($before['revocation'] !== null) {
                    return $before;
                }

                DB::table('transport_handover_revocations')->insert([
                    'id' => (string) Str::uuid(),
                    'school_id' => $actor->school_id,
                    'authorization_id' => $id,
                    'reason' => $data['reason'],
                    'revoked_by' => $actor->getKey(),
                ]);

                $after = $this->response($row);
                $this->audit->record(
                    $actor, 'revoke', 'transport_handover_authorizations', $id,
                    $before, $after
                );

                return $after;
            });
        } catch (QueryException $exception) {
            $this->translate($exception);
        }
    }

    public function show(User $authenticated, string $id): array
    {
        $actor = $this->access->actor($authenticated);
        $this->access->requirePermission($actor, 'view_transport_operations');

        return $this->response($this->find((string) $actor->school_id, $id));
    }

    private function find(string $school, string $id): object
    {
        $row = DB::table('transport_handover_authorizations')
            ->where('school_id', $school)->where('id', $id)->first();
        abort_unless($row !== null, 404);

        return $row;
    }

    private function response(object $row): array
    {
        $revocation = DB::table('transport_handover_revocations')
            ->where('school_id', $row->school_id)
            ->where('authorization_id', $row->id)->first();

        return [
            'id' => (string) $row->id,
            'kind' => (string) $row->kind,
            'identity' => json_decode($row->identity_snapshot, true, 512, JSON_THROW_ON_ERROR),
            'scope' => json_decode($row->scope_snapshot, true, 512, JSON_THROW_ON_ERROR),
            'reason' => (string) $row->reason,
            'authorized_by' => (string) $row->authorized_by,
            'authorized_at' => $row->authorized_at,
            'revocation' => $revocation === null ? null : [
                'id' => (string) $revocation->id,
                'reason' => (string) $revocation->reason,
                'revoked_by' => (string) $revocation->revoked_by,
                'revoked_at' => $revocation->revoked_at,
            ],
            'version' => hash('sha256', 'transport-handover:'.$row->id.':'.($revocation->id ?? 'issued')),
        ];
    }

    private function permission(string $kind): string
    {
        return $kind === 'guardian_recipient'
            ? 'authorize_transport_handover'
            : 'manage_transport';
    }

    private function rejectUnknown(array $input, array $allowed): void
    {
        foreach (array_diff(array_keys($input), $allowed) as $key) {
            throw ValidationException::withMessages([$key => ['This field is prohibited.']]);
        }
    }

    private function translate(QueryException $exception): never
    {
        $state = $exception->errorInfo[0] ?? null;
        if ($state === '23514') {
            abort(422, 'Handover authorization scope or eligibility is invalid.');
        }
        if ($state === '23505') {
            abort(409, 'Handover authorization conflicts with existing evidence.');
        }

        throw $exception;
    }
}
