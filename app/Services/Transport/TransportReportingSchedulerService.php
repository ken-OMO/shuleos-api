<?php

declare(strict_types=1);

namespace App\Services\Transport;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class TransportReportingSchedulerService
{
    public function __construct(
        private TransportAccessService $access,
        private TransportReportingObligationService $obligations,
    ) {}

    public function run(): array
    {
        $result = [
            'enabled' => config('transport_reporting.scheduler_enabled', false) === true,
            'created' => 0, 'existing' => 0, 'failed' => 0, 'schools' => [],
        ];
        if (! $result['enabled']) {
            return $result;
        }

        $configured = config('transport_reporting.scheduler_actors', []);
        if (! is_array($configured) || count($configured) > 1000) {
            throw new RuntimeException('Invalid Transport reporting scheduler actor configuration.');
        }
        $actors = [];
        // Validate the entire mapping before the first school mutation.
        foreach ($configured as $schoolId => $actorId) {
            if (! is_string($schoolId) || ! Str::isUuid($schoolId)
                || ! is_string($actorId) || ! Str::isUuid($actorId)) {
                throw new RuntimeException('Transport scheduler mappings require school and actor UUIDs.');
            }
            $schoolId = strtolower($schoolId);
            if (array_key_exists($schoolId, $actors)) {
                throw new RuntimeException('Duplicate Transport scheduler school mapping.');
            }
            $actors[$schoolId] = strtolower($actorId);
        }

        foreach ($actors as $schoolId => $actorId) {
            $actor = User::withoutGlobalScopes()->where('school_id', $schoolId)->whereKey($actorId)->first();
            if ($actor === null) {
                $result['failed']++;
                $result['schools'][] = ['school_id' => $schoolId, 'status' => 'access_unavailable', 'created' => 0, 'existing' => 0];

                continue;
            }
            try {
                $schoolResult = $this->materializeSchool($actor);
                $result['created'] += $schoolResult['created'];
                $result['existing'] += $schoolResult['existing'];
                $result['schools'][] = $schoolResult;
            } catch (AuthenticationException|AuthorizationException) {
                $result['failed']++;
                $result['schools'][] = ['school_id' => $schoolId, 'status' => 'access_unavailable', 'created' => 0, 'existing' => 0];
            } catch (HttpExceptionInterface $exception) {
                if (! in_array($exception->getStatusCode(), [401, 403, 404, 409, 422], true)) {
                    throw $exception;
                }
                $result['failed']++;
                $result['schools'][] = [
                    'school_id' => $schoolId,
                    'status' => in_array($exception->getStatusCode(), [401, 403, 404], true)
                        ? 'access_unavailable' : 'materialization_rejected',
                    'created' => 0, 'existing' => 0,
                ];
            }
            // Unexpected failures propagate, including strict audit failures.
            // Earlier schools may have committed; retries are idempotent.
        }

        return $result;
    }

    private function materializeSchool(User $authenticated): array
    {
        // One school transaction: any rejected policy or failed audit rolls back
        // all obligations created for that school during this run.
        return DB::transaction(function () use ($authenticated): array {
            $actor = $this->access->actor($authenticated, true);
            $this->access->requirePermission($actor, 'manage_transport');
            $now = CarbonImmutable::now('UTC')->toIso8601String();
            $policies = DB::table('transport_reporting_policies as p')
                ->join('transport_service_schedules as s', function ($join): void {
                    $join->on('s.school_id', '=', 'p.school_id')->on('s.id', '=', 'p.schedule_id');
                })
                ->where('p.school_id', $actor->school_id)->where('s.active', true)
                ->whereRaw('p.effective_from <= (?::timestamptz AT TIME ZONE p.timezone)::date', [$now])
                ->whereRaw('p.effective_to >= (?::timestamptz AT TIME ZONE p.timezone)::date', [$now])
                ->whereRaw('(p.weekday_mask & (1 << (extract(isodow FROM (?::timestamptz AT TIME ZONE p.timezone))::integer - 1))) <> 0', [$now])
                ->orderBy('p.id')->sharedLock()->get(['p.id', 'p.timezone', 'p.effective_from', 'p.effective_to', 'p.weekday_mask']);
            $before = DB::table('transport_reporting_obligations')->where('school_id', $actor->school_id)->count();
            $processed = [];
            $dates = [];
            foreach ($policies as $policy) {
                // Midnight may pass after selection. Never pass an historical date
                // to the shared materializer; it derives the current local date.
                $local = CarbonImmutable::now($policy->timezone);
                $date = $local->toDateString();
                if ($date < $policy->effective_from || $date > $policy->effective_to
                    || (((int) $policy->weekday_mask & (1 << ($local->dayOfWeekIso - 1))) === 0)) {
                    continue;
                }
                $obligation = $this->obligations->materialize($actor, (string) $policy->id);
                $processed[$obligation['id']] = true;
                $dates[$obligation['service_date']] = true;
            }
            $created = DB::table('transport_reporting_obligations')->where('school_id', $actor->school_id)->count() - $before;
            $reportingDates = array_keys($dates);
            sort($reportingDates);

            return [
                'school_id' => (string) $actor->school_id, 'status' => 'completed',
                'created' => $created, 'existing' => count($processed) - $created,
                'reporting_dates' => $reportingDates,
            ];
        }, 3);
    }
}
