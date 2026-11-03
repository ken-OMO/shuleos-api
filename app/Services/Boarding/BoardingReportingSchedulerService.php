<?php

declare(strict_types=1);

namespace App\Services\Boarding;

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class BoardingReportingSchedulerService
{
    public function __construct(private BoardingReportingPolicyService $policies) {}

    public function run(): array
    {
        $result = [
            'enabled' => (bool) config('boarding_reporting.scheduler_enabled', false),
            'created' => 0,
            'failed' => 0,
            'schools' => [],
        ];

        if (! $result['enabled']) {
            return $result;
        }

        $actors = config('boarding_reporting.scheduler_actors', []);

        if (! is_array($actors) || count($actors) > 1000) {
            throw new RuntimeException('Invalid boarding reporting scheduler actor configuration.');
        }

        // Validate the complete mapping before any school mutation.
        foreach ($actors as $schoolId => $actorId) {
            if (! is_string($schoolId) || ! Str::isUuid($schoolId)
                || ! is_string($actorId) || ! Str::isUuid($actorId)) {
                throw new RuntimeException('Scheduler actor mappings require school and user UUIDs.');
            }
        }

        foreach ($actors as $schoolId => $actorId) {
            $actor = User::withoutGlobalScopes()
                ->whereKey($actorId)->where('school_id', $schoolId)->first();

            if (! $actor) {
                $result['failed']++;
                $result['schools'][] = [
                    'school_id' => $schoolId,
                    'status' => 'access_unavailable',
                    'created' => 0,
                ];

                continue;
            }

            try {
                // Rechecks current user, permission, school mode, lifecycle and setup.
                // The shared service owns the transaction, locks and strict audit.
                $created = $this->policies->materializeToday($actor);
                $result['created'] += $created['created'];
                $result['schools'][] = [
                    'school_id' => $schoolId,
                    'status' => 'completed',
                    'created' => $created['created'],
                    'reporting_date' => $created['reporting_date'],
                ];
            } catch (AuthenticationException|AuthorizationException|HttpExceptionInterface) {
                $result['failed']++;
                $result['schools'][] = [
                    'school_id' => $schoolId,
                    'status' => 'access_unavailable',
                    'created' => 0,
                ];
            }

            // Unexpected failures propagate. They must not be reported as success.
            // Earlier schools may have committed; retries are idempotent.
        }

        return $result;
    }
}
