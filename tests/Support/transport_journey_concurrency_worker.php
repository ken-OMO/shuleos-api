<?php

declare(strict_types=1);

use App\Models\User;
use App\Services\Transport\TransportAccessService;
use App\Services\Transport\TransportAssignmentService;
use App\Services\Transport\TransportAttendanceService;
use App\Services\Transport\TransportDailyReportService;
use App\Services\Transport\TransportExceptionService;
use App\Services\Transport\TransportHandoverAuthorizationService;
use App\Services\Transport\TransportJourneyEventService;
use App\Services\Transport\TransportLearnerReservationService;
use App\Services\Transport\TransportReportingSchedulerService;
use App\Services\TransportSubscription\TransportAddonService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

require dirname(__DIR__, 2).'/vendor/autoload.php';

function transportJourneyWorkerMessage(array $message): void
{
    echo json_encode($message, JSON_THROW_ON_ERROR).PHP_EOL;
    flush();
}

try {
    $line = fgets(STDIN);
    if ($line === false) {
        throw new RuntimeException('Missing worker input.');
    }

    $payload = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
    $connection = $payload['connection'];

    if (($connection['driver'] ?? null) !== 'pgsql'
        || ($connection['database'] ?? null) !== 'shuleos_assessment_fk_test'
        || ! empty($connection['url'])) {
        throw new RuntimeException('Unexpected test connection.');
    }

    putenv('APP_ENV=testing');
    $_ENV['APP_ENV'] = 'testing';
    $_SERVER['APP_ENV'] = 'testing';

    $app = require dirname(__DIR__, 2).'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();

    if (! $app->environment('testing') || $app->configurationIsCached()) {
        throw new RuntimeException('Uncached testing configuration required.');
    }

    $name = config('database.default');
    config([
        'database.connections.'.$name => $connection,
        'logging.default' => 'stderr',
        'boarding_reporting.scheduler_enabled' => false,
    ]);
    DB::purge($name);

    $actual = DB::selectOne(
        'SELECT current_database() AS name, pg_backend_pid() AS pid'
    );

    if ($actual->name !== 'shuleos_assessment_fk_test') {
        throw new RuntimeException('Unexpected actual database.');
    }

    DB::statement("SET lock_timeout = '10s'");
    DB::statement("SET statement_timeout = '15s'");

    transportJourneyWorkerMessage(['event' => 'ready', 'pid' => (int) $actual->pid]);

    CarbonImmutable::setTestNow('2026-11-09T04:30:00Z');

    DB::beginTransaction();

    if ($payload['operation'] === 'addon_main_lock') {
        $row = DB::table('school_subscriptions')->where('school_id', $payload['school_id'])
            ->lockForUpdate()->first();
        if ($row === null) {
            throw new RuntimeException('Main subscription fixture missing.');
        }
        $result = ['id' => $row->id];
    } elseif ($payload['operation'] === 'addon_normal') {
        CarbonImmutable::setTestNow($payload['at']);
        if ($payload['expire_after_main_lock'] ?? false) {
            // Test clock changes when the blocked main-row read actually returns.
            // The runner separately proves real PostgreSQL lock contention.
            DB::listen(static function ($query): void {
                $sql = strtolower($query->sql);
                if (str_contains($sql, '"school_subscriptions"') && str_contains($sql, 'for share')) {
                    CarbonImmutable::setTestNow('2026-11-09T04:31:00Z');
                }
            });
        }
        $actor = User::withoutGlobalScopes()->where('school_id', $payload['school_id'])->findOrFail($payload['actor_id']);
        $checked = app(TransportAccessService::class)->actor($actor, true);
        $result = ['id' => $checked->getRelation('transportAccessBasis')->period_id];
    } elseif ($payload['operation'] === 'addon_renew') {
        $owner = User::withoutGlobalScopes()->whereNull('school_id')->findOrFail($payload['actor_id']);
        $issued = app(TransportAddonService::class)->issue($owner, $payload['school_id'], $payload['input']);
        $result = ['id' => $issued['period']['id'], 'replayed' => $issued['replayed']];
    } elseif ($payload['operation'] === 'database') {
        $row = $payload['assignment'];

        // Connection and school are verified independently of input row data.
        if ((string) $row['school_id'] !== (string) $payload['school_id']) {
            throw new RuntimeException('Fixture school mismatch.');
        }

        DB::table('transport_dated_assignments')->insert($row);
        $result = ['id' => $row['id']];
    } elseif ($payload['operation'] === 'reservation_database') {
        $row = $payload['reservation'];
        if ((string) $row['school_id'] !== (string) $payload['school_id']) {
            throw new RuntimeException('Reservation fixture school mismatch.');
        }
        DB::table('transport_learner_reservations')->insert($row);
        $result = ['id' => $row['id']];
    } elseif ($payload['operation'] === 'reservation_service') {
        $actor = User::withoutGlobalScopes()
            ->where('school_id', $payload['school_id'])
            ->findOrFail($payload['actor_id']);
        auth()->guard()->setUser($actor);
        $result = app(TransportLearnerReservationService::class)
            ->create($actor, $payload['input']);
    } elseif ($payload['operation'] === 'service') {
        $actor = User::withoutGlobalScopes()
            ->where('school_id', $payload['school_id'])
            ->findOrFail($payload['actor_id']);
        auth()->guard()->setUser($actor);

        $result = app(TransportAssignmentService::class)
            ->create($actor, $payload['input']);
    } elseif ($payload['operation'] === 'journey_event') {
        $actor = User::withoutGlobalScopes()
            ->where('school_id', $payload['school_id'])
            ->findOrFail($payload['actor_id']);
        auth()->guard()->setUser($actor);

        $journey = app(TransportJourneyEventService::class)
            ->record($actor, $payload['roster_id'], $payload['input']);
        $events = $journey['events'];
        $last = end($events);

        if (! is_array($last) || ! isset($last['id'])) {
            throw new RuntimeException('Journey operation returned no event.');
        }

        $result = ['id' => $last['id']];
    } elseif ($payload['operation'] === 'journey_database') {
        $row = $payload['row'];
        if ((string) $row['school_id'] !== (string) $payload['school_id']) {
            throw new RuntimeException('Fixture school mismatch.');
        }

        DB::table('transport_journey_events')->insert($row);
        $result = ['id' => $row['id']];
    } elseif ($payload['operation'] === 'journey_revoke') {
        $actor = User::withoutGlobalScopes()
            ->where('school_id', $payload['school_id'])
            ->findOrFail($payload['actor_id']);
        auth()->guard()->setUser($actor);

        $result = app(TransportHandoverAuthorizationService::class)
            ->revoke($actor, $payload['authorization_id'], $payload['input']);
    } elseif ($payload['operation'] === 'journey_revoke_database') {
        $row = $payload['row'];
        if ((string) $row['school_id'] !== (string) $payload['school_id']) {
            throw new RuntimeException('Fixture school mismatch.');
        }

        DB::table('transport_handover_revocations')->insert($row);
        $result = ['id' => $row['id']];
    } elseif ($payload['operation'] === 'exception_action') {
        $actor = User::withoutGlobalScopes()
            ->where('school_id', $payload['school_id'])
            ->findOrFail($payload['actor_id']);
        auth()->guard()->setUser($actor);

        $exception = app(TransportExceptionService::class)
            ->append($actor, $payload['failure_id'], $payload['input']);
        $history = $exception['history'];
        $last = end($history);

        if (! is_array($last) || ! isset($last['id'])) {
            throw new RuntimeException('Exception action returned no history row.');
        }

        $result = ['id' => $last['id']];
    } elseif ($payload['operation'] === 'exception_database') {
        $row = $payload['row'];
        if ((string) $row['school_id'] !== (string) $payload['school_id']) {
            throw new RuntimeException('Fixture school mismatch.');
        }

        DB::table('transport_exception_follow_ups')->insert($row);
        $result = ['id' => $row['id']];
    } elseif ($payload['operation'] === 'attendance_outcome') {
        $actor = User::withoutGlobalScopes()
            ->where('school_id', $payload['school_id'])
            ->findOrFail($payload['actor_id']);
        auth()->guard()->setUser($actor);

        app(TransportAttendanceService::class)
            ->recordOutcome($actor, $payload['roster_id'], $payload['input']);

        $id = DB::table('transport_journey_outcomes')
            ->where('school_id', $payload['school_id'])
            ->where('roster_id', $payload['roster_id'])->value('id');

        if ($id === null) {
            throw new RuntimeException('Attendance outcome was not persisted.');
        }

        $result = ['id' => $id];
    } elseif ($payload['operation'] === 'attendance_completion') {
        $actor = User::withoutGlobalScopes()
            ->where('school_id', $payload['school_id'])
            ->findOrFail($payload['actor_id']);
        auth()->guard()->setUser($actor);

        $trip = app(TransportAttendanceService::class)
            ->completeTrip($actor, $payload['trip_id'], $payload['input']);
        $history = $trip['completion_history'];
        $last = end($history);

        if (! is_array($last) || ! isset($last['id'])) {
            throw new RuntimeException('Completion operation returned no history row.');
        }

        $result = ['id' => $last['id']];
    } elseif ($payload['operation'] === 'attendance_outcome_database') {
        $row = $payload['row'];
        if ((string) $row['school_id'] !== (string) $payload['school_id']) {
            throw new RuntimeException('Fixture school mismatch.');
        }

        DB::table('transport_journey_outcomes')->insert($row);
        $result = ['id' => $row['id']];
    } elseif ($payload['operation'] === 'attendance_completion_database') {
        $row = $payload['row'];
        if ((string) $row['school_id'] !== (string) $payload['school_id']) {
            throw new RuntimeException('Fixture school mismatch.');
        }

        DB::table('transport_trip_completions')->insert($row);
        $result = ['id' => $row['id']];
    } elseif ($payload['operation'] === 'daily_submit') {
        CarbonImmutable::setTestNow('2026-11-09T06:00:00Z');
        $actor = User::withoutGlobalScopes()->where('school_id', $payload['school_id'])->findOrFail($payload['actor_id']);
        auth()->guard()->setUser($actor);
        $report = app(TransportDailyReportService::class)->submit($actor, $payload['obligation_id'], $payload['input']);
        $last = $report['submissions'][array_key_last($report['submissions'])];
        $result = ['id' => $last['id']];
    } elseif ($payload['operation'] === 'daily_review') {
        CarbonImmutable::setTestNow('2026-11-09T06:00:00Z');
        $actor = User::withoutGlobalScopes()->where('school_id', $payload['school_id'])->findOrFail($payload['actor_id']);
        auth()->guard()->setUser($actor);
        $report = app(TransportDailyReportService::class)->review($actor, $payload['submission_id'], $payload['input']);
        $last = $report['submissions'][array_key_last($report['submissions'])];
        $result = ['id' => $last['review']['id']];
    } elseif ($payload['operation'] === 'reporting_scheduler') {
        config([
            'transport_reporting.scheduler_enabled' => true,
            'transport_reporting.scheduler_actors' => [$payload['school_id'] => $payload['actor_id']],
        ]);
        $scheduled = app(TransportReportingSchedulerService::class)->run();
        $result = [
            'id' => DB::table('transport_reporting_obligations')->where('school_id', $payload['school_id'])->value('id'),
            'created' => $scheduled['created'], 'existing' => $scheduled['existing'], 'failed' => $scheduled['failed'],
        ];
    } else {
        throw new RuntimeException('Unsupported concurrency operation.');
    }

    // Hold after the assignment and its staff reservations exist uncommitted.
    if ($payload['hold_lock'] ?? false) {
        transportJourneyWorkerMessage(['event' => 'locked']);
        $release = fgets(STDIN);

        if ($release === false || trim($release) !== 'release') {
            throw new RuntimeException('Worker was not released.');
        }
    }

    $rolledBack = (bool) ($payload['rollback'] ?? false);

    if ($rolledBack) {
        DB::rollBack();
    } else {
        DB::commit();
    }

    transportJourneyWorkerMessage([
        'event' => 'finished',
        'result' => [...$result, 'rolled_back' => $rolledBack],
    ]);
} catch (HttpExceptionInterface $exception) {
    if (DB::transactionLevel() > 0) {
        DB::rollBack();
    }
    transportJourneyWorkerMessage([
        'event' => 'rejected',
        'http_status' => $exception->getStatusCode(),
    ]);
    exit(2);
} catch (QueryException $exception) {
    if (DB::transactionLevel() > 0) {
        DB::rollBack();
    }
    transportJourneyWorkerMessage([
        'event' => 'rejected',
        'sqlstate' => $exception->errorInfo[0] ?? null,
    ]);
    exit(2);
} catch (Throwable $exception) {
    if (class_exists(DB::class) && DB::transactionLevel() > 0) {
        DB::rollBack();
    }
    transportJourneyWorkerMessage([
        'event' => 'error',
        'type' => get_class($exception),
        'message' => $exception->getMessage(),
    ]);
    exit(1);
}
