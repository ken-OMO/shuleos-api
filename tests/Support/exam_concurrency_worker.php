<?php

use App\Services\Assessment\ExamResultLifecycleService;
use App\Services\Assessment\ExamResultService;
use App\Services\Assessment\ExamRosterService;
use App\Services\Assessment\ReportCardService;
use App\Services\Assessment\ResultProcessingService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

require dirname(__DIR__, 2).'/vendor/autoload.php';

function workerMessage(array $message): void
{
    echo json_encode($message, JSON_THROW_ON_ERROR).PHP_EOL;
    flush();
}

try {
    $input = fgets(STDIN);
    if ($input === false) {
        throw new RuntimeException('Missing worker input.');
    }

    $payload = json_decode($input, true, 512, JSON_THROW_ON_ERROR);
    $connection = $payload['connection'];
    $database = $connection['database'] ?? '';

    if (($connection['driver'] ?? '') !== 'pgsql'
        || ! is_string($database)
        || ! preg_match('/(?:^|_)test(?:ing)?(?:_|$)/i', $database)
        || ! empty($connection['url'])) {
        throw new RuntimeException('A named PostgreSQL testing database is required.');
    }

    putenv('APP_ENV=testing');
    $_ENV['APP_ENV'] = 'testing';
    $_SERVER['APP_ENV'] = 'testing';

    $app = require dirname(__DIR__, 2).'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();

    if (! $app->environment('testing')) {
        throw new RuntimeException('Worker requires the testing environment.');
    }

    $connectionName = config('database.default');
    config([
        'database.connections.'.$connectionName => $connection,
        'logging.default' => 'stderr',
    ]);
    DB::purge($connectionName);

    $actual = DB::selectOne('select current_database() as name, pg_backend_pid() as pid');
    if ($actual->name !== $database) {
        throw new RuntimeException('Worker database does not match the test connection.');
    }

    DB::statement("SET lock_timeout = '10s'");
    DB::statement("SET statement_timeout = '15s'");

    workerMessage(['event' => 'ready', 'pid' => (int) $actual->pid]);

    $result = DB::transaction(function () use ($payload) {
        if ($payload['hold_lock'] ?? false) {
            app(ExamResultLifecycleService::class)->lockExam(
                $payload['school_id'], $payload['exam_id']
            );
            workerMessage(['event' => 'locked']);

            $release = fgets(STDIN);
            if ($release === false || trim($release) !== 'release') {
                throw new RuntimeException('Worker lock was not released by the test.');
            }
        }

        return match ($payload['operation']) {
            'process' => app(ResultProcessingService::class)->process(
                $payload['school_id'],
                $payload['exam_learning_area_id'],
                $payload['learner_id'],
                $payload['actor_id']
            )->id,
            'marks' => app(ExamResultService::class)->updateMarks(
                $payload['school_id'],
                $payload['result_id'],
                $payload['marks'],
                $payload['actor_id']
            )->id,
            'publish' => app(ReportCardService::class)->publish(
                $payload['school_id'],
                $payload['exam_id'],
                $payload['learner_id'] ?? null,
                null,
                null,
                $payload['actor_id']
            )->count(),
            'roster' => app(ExamRosterService::class)->replace(
                $payload['school_id'],
                $payload['exam_id'],
                $payload['actor_id'],
                $payload['version'],
                $payload['selections']
            )['version'],
            default => throw new RuntimeException('Unsupported worker operation.'),
        };
    });

    workerMessage(['event' => 'finished', 'result' => $result]);
} catch (ValidationException $exception) {
    workerMessage([
        'event' => 'rejected',
        'fields' => array_keys($exception->errors()),
    ]);
    exit(2);
} catch (Throwable $exception) {
    // Do not expose connection settings or SQL bindings in worker output.
    workerMessage(['event' => 'error', 'type' => get_class($exception)]);
    exit(1);
}
