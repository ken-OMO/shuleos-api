<?php

declare(strict_types=1);

use App\Models\User;
use App\Services\Transport\TransportAssignmentService;
use App\Services\Transport\TransportLearnerReservationService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

require dirname(__DIR__, 2).'/vendor/autoload.php';

function transportWorkerMessage(array $message): void
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

    transportWorkerMessage(['event' => 'ready', 'pid' => (int) $actual->pid]);

    DB::beginTransaction();

    if ($payload['operation'] === 'database') {
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
    } else {
        throw new RuntimeException('Unsupported concurrency operation.');
    }

    // Hold after the assignment and its staff reservations exist uncommitted.
    if ($payload['hold_lock'] ?? false) {
        transportWorkerMessage(['event' => 'locked']);
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

    transportWorkerMessage([
        'event' => 'finished',
        'result' => ['id' => $result['id'], 'rolled_back' => $rolledBack],
    ]);
} catch (HttpExceptionInterface $exception) {
    if (DB::transactionLevel() > 0) {
        DB::rollBack();
    }
    transportWorkerMessage([
        'event' => 'rejected',
        'http_status' => $exception->getStatusCode(),
    ]);
    exit(2);
} catch (QueryException $exception) {
    if (DB::transactionLevel() > 0) {
        DB::rollBack();
    }
    transportWorkerMessage([
        'event' => 'rejected',
        'sqlstate' => $exception->errorInfo[0] ?? null,
    ]);
    exit(2);
} catch (Throwable $exception) {
    if (class_exists(DB::class) && DB::transactionLevel() > 0) {
        DB::rollBack();
    }
    transportWorkerMessage([
        'event' => 'error',
        'type' => get_class($exception),
        'message' => $exception->getMessage(),
    ]);
    exit(1);
}
