<?php

use App\Http\Controllers\Api\GradeController;
use App\Http\Controllers\Api\LearnerController;
use App\Http\Controllers\Api\LearnerPlacementController;
use App\Models\Exam;
use App\Models\ExamLearningArea;
use App\Models\ExamPaper;
use App\Models\User;
use App\Services\Assessment\ExamLearningAreaService;
use App\Services\Assessment\ExamPaperService;
use App\Services\Assessment\ExamResultLifecycleService;
use App\Services\Assessment\ExamResultService;
use App\Services\Assessment\ExamRosterService;
use App\Services\Assessment\ExamService;
use App\Services\Assessment\ReportCardService;
use App\Services\Assessment\ResultProcessingService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

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
            if ($payload['operation'] === 'grade_classify') {
                DB::table('grades')->where('school_id', $payload['school_id'])
                    ->where('id', $payload['grade_id'])
                    ->lockForUpdate()->firstOrFail();
            } elseif (in_array($payload['operation'], ['learner_promote', 'learner_clear_assessment'], true)) {
                DB::table('learners')->where('school_id', $payload['school_id'])
                    ->where('id', $payload['learner_id'])
                    ->lockForUpdate()->firstOrFail();
            } elseif ($payload['operation'] === 'school_read_only') {
                DB::table('schools')->where('id', $payload['school_id'])
                    ->lockForUpdate()->firstOrFail();
            } else {
                app(ExamResultLifecycleService::class)->lockExamForWrite(
                    $payload['school_id'], $payload['exam_id']
                );
            }
            workerMessage(['event' => 'locked']);

            $release = fgets(STDIN);
            if ($release === false || trim($release) !== 'release') {
                throw new RuntimeException('Worker lock was not released by the test.');
            }
        }

        if ($payload['operation'] === 'school_read_only') {
            // Exercise the same exclusive school-row lock as lifecycle changes.
            // This worker tests database serialization, not platform permissions.
            DB::table('schools')->where('id', $payload['school_id'])
                ->lockForUpdate()->firstOrFail();

            return DB::table('schools')->where('id', $payload['school_id'])
                ->update(['lifecycle_state' => 'read_only']);
        }

        if ($payload['operation'] === 'grade_classify') {
            $actor = User::withoutGlobalScopes()
                ->where('school_id', $payload['school_id'])
                ->findOrFail($payload['actor_id']);
            auth()->guard()->setUser($actor);

            $request = Request::create(
                '/api/grades/'.$payload['grade_id'],
                'PUT',
                ['grade_number' => $payload['grade_number']]
            );
            $request->attributes->set('tenant_school_id', $payload['school_id']);
            $request->setUserResolver(fn () => $actor);

            $response = app(GradeController::class)
                ->update($request, $payload['grade_id']);

            if ($response->getStatusCode() !== 200) {
                throw new RuntimeException(
                    'Unexpected grade response: '.$response->getStatusCode()
                );
            }

            return DB::table('grades')->where('id', $payload['grade_id'])
                ->value('grade_number');
        }

        if (in_array($payload['operation'], ['learner_promote', 'learner_clear_assessment'], true)) {
            $actor = User::withoutGlobalScopes()
                ->where('school_id', $payload['school_id'])
                ->findOrFail($payload['actor_id']);
            auth()->guard()->setUser($actor);

            $promoting = $payload['operation'] === 'learner_promote';
            $request = Request::create(
                '/api/learners/'.$payload['learner_id'],
                $promoting ? 'POST' : 'PUT',
                $promoting ? [
                    'grade_id' => $payload['destination_grade_id'],
                    'stream_id' => $payload['destination_stream_id'],
                    'reason' => 'Assessment-number concurrency regression.',
                ] : ['assessment_no' => null]
            );
            $request->attributes->set('tenant_school_id', $payload['school_id']);
            $request->setUserResolver(fn () => $actor);

            $response = $promoting
                ? app(LearnerPlacementController::class)
                    ->store($request, $payload['learner_id'])
                : app(LearnerController::class)
                    ->update($request, $payload['learner_id']);

            if ($response->getStatusCode() !== 200) {
                throw new RuntimeException(
                    'Unexpected learner response: '.$response->getStatusCode()
                );
            }

            return $response->getStatusCode();
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
            'paper_create' => app(ExamPaperService::class)
                ->create($payload['paper'], $payload['school_id'])->id,
            'exam_publish' => app(ExamService::class)
                ->update(
                    Exam::withoutGlobalScopes()
                        ->where('school_id', $payload['school_id'])
                        ->findOrFail($payload['exam_id']),
                    ['status' => 'published']
                )->id,
            'paper_update' => app(ExamPaperService::class)
                ->update(
                    ExamPaper::withoutGlobalScopes()
                        ->findOrFail($payload['paper_id']),
                    ['max_marks' => $payload['max_marks']],
                    $payload['school_id']
                )->id,
            'area_delete' => app(ExamLearningAreaService::class)
                ->delete(
                    ExamLearningArea::withoutGlobalScopes()
                        ->findOrFail($payload['exam_learning_area_id']),
                    $payload['school_id'],
                    $payload['actor_id']
                ),
            'exam_delete' => app(ExamService::class)
                ->delete(
                    Exam::withoutGlobalScopes()
                        ->where('school_id', $payload['school_id'])
                        ->findOrFail($payload['exam_id']),
                    $payload['actor_id']
                ),
            'area_create' => app(ExamLearningAreaService::class)
                ->create([
                    'exam_id' => $payload['exam_id'],
                    'learning_area_id' => $payload['area_id'],
                    'number_of_papers' => 1,
                    'total_marks' => 100,
                ], $payload['school_id'])->id,
            default => throw new RuntimeException('Unsupported worker operation.'),
        };
    });

    workerMessage(['event' => 'finished', 'result' => $result]);
} catch (AuthorizationException $exception) {
    workerMessage([
        'event' => 'rejected',
        'fields' => ['school_access'],
    ]);
    exit(2);
} catch (ValidationException $exception) {
    workerMessage([
        'event' => 'rejected',
        'fields' => array_keys($exception->errors()),
    ]);
    exit(2);
} catch (HttpExceptionInterface $exception) {
    workerMessage([
        'event' => 'rejected',
        'fields' => [],
        'http_status' => $exception->getStatusCode(),
    ]);
    exit(2);
} catch (Throwable $exception) {
    // Do not expose connection settings or SQL bindings in worker output.
    workerMessage(['event' => 'error', 'type' => get_class($exception)]);
    exit(1);
}
