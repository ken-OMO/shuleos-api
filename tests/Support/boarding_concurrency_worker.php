<?php

declare(strict_types=1);

use App\Models\User;
use App\Services\Boarding\BoardingDailyReportService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

require dirname(__DIR__, 2).'/vendor/autoload.php';

function boardingWorkerMessage(array $message): void
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

    boardingWorkerMessage([
        'event' => 'ready',
        'pid' => (int) $actual->pid,
    ]);

    $result = DB::transaction(function () use ($payload): array {
        if ($payload['hold_lock'] ?? false) {
            DB::table('schools')->where('id', $payload['school_id'])
                ->lockForUpdate()->firstOrFail();

            boardingWorkerMessage(['event' => 'locked']);

            $release = fgets(STDIN);
            if ($release === false || trim($release) !== 'release') {
                throw new RuntimeException('Worker was not released.');
            }
        }

        $actor = User::withoutGlobalScopes()
            ->where('school_id', $payload['school_id'])
            ->findOrFail($payload['actor_id']);
        auth()->guard()->setUser($actor);

        $service = app(BoardingDailyReportService::class);

        $report = match ($payload['operation']) {
            'open' => $service->open($actor, $payload['obligation_id']),
            'edit' => $service->updateDraft(
                $actor,
                $payload['revision_id'],
                $payload['version'],
                [
                    'summary' => $payload['summary'],
                    'no_occurrences' => true,
                ]
            ),
            'submit' => $service->submit(
                $actor, $payload['revision_id'], $payload['version']
            ),
            'review' => $service->review(
                $actor,
                $payload['submission_id'],
                $payload['decision'],
                'Concurrency review.'
            ),
            'correction' => $service->openCorrection(
                $actor, $payload['report_id']
            ),
            default => throw new RuntimeException('Unsupported operation.'),
        };

        return array_intersect_key($report, array_flip([
            'report_id', 'revision_id', 'revision_number',
            'version', 'state', 'summary', 'submission_id',
        ]));
    });

    boardingWorkerMessage(['event' => 'finished', 'result' => $result]);
} catch (HttpExceptionInterface $exception) {
    boardingWorkerMessage([
        'event' => 'rejected',
        'http_status' => $exception->getStatusCode(),
    ]);
    exit(2);
} catch (ValidationException $exception) {
    boardingWorkerMessage([
        'event' => 'rejected',
        'fields' => array_keys($exception->errors()),
    ]);
    exit(2);
} catch (Throwable $exception) {
    boardingWorkerMessage([
        'event' => 'error',
        'type' => get_class($exception),
    ]);
    exit(1);
}
