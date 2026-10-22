<?php

use App\Services\Assessment\ExamCompletenessService;
use App\Services\Assessment\ResultProcessingService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\ExamBenchmarkFixture;
use Tests\Support\ExamBenchmarkMeter;

require dirname(__DIR__, 2).'/vendor/autoload.php';

putenv('APP_ENV=testing');
$_ENV['APP_ENV'] = 'testing';
$_SERVER['APP_ENV'] = 'testing';

$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
if (file_exists(dirname(__DIR__, 2).'/.env.testing')) {
    $app->loadEnvironmentFrom('.env.testing');
}
$app->make(Kernel::class)->bootstrap();

$count = filter_var($argv[1] ?? '251', FILTER_VALIDATE_INT);
$mode = $argv[2] ?? 'reprocess';
if (! in_array($mode, ['first-time', 'reprocess'], true)) {
    throw new RuntimeException('Mode must be first-time or reprocess.');
}
$db = DB::connection();
$config = $db->getConfig();

if (! $app->environment('testing')
    || $db->getDriverName() !== 'pgsql'
    || ! preg_match('/(?:^|_)test(?:ing)?(?:_|$)/i', $db->getDatabaseName())
    || ! empty($config['url'])
    || $db->transactionLevel() !== 0
    || $count === false || $count < 1 || $count > 10000) {
    throw new RuntimeException('Requires a named PostgreSQL testing database and 1–10000 learners.');
}

set_time_limit(1800);
$db->disableQueryLog();
$db->statement("SET statement_timeout = '120s'");
$db->statement("SET lock_timeout = '5s'");

$run = (string) Str::uuid();
$marker = 'Processing benchmark '.$run;
$output = storage_path('logs/exam-processing-'.$count.'-'.$run.'.jsonl');
$manifestPath = storage_path('logs/exam-processing-'.$run.'-fixture.json');
$emit = function (array $row) use ($output): void {
    $line = json_encode($row, JSON_THROW_ON_ERROR);
    if (file_put_contents($output, $line.PHP_EOL, FILE_APPEND | LOCK_EX) === false) {
        throw new RuntimeException('Cannot write benchmark results.');
    }
    echo $line.PHP_EOL;
    flush();
};

$fixture = null;
$manifest = null;
$committed = false;
$failed = false;
$meter = new ExamBenchmarkMeter;

try {
    $emit([
        'operation' => 'metadata',
        'learners' => $count,
        'processing_operations' => $count * 6,
        'transaction_mode' => 'committed_fixture_individual_service_transactions',
        'scope' => $mode === 'first-time'
            ? 'service_first_time_processing_of_synthetic_paper_results'
            : 'service_reprocessing_of_synthetic_existing_results',
        'processing_mode' => $mode,
        'production_roster_admission_limit' => 2000,
    ]);

    DB::beginTransaction();
    $fixture = ExamBenchmarkFixture::create($count);

    if ($mode === 'first-time') {
        DB::table('learning_area_results')
            ->where('school_id', $fixture['school_id'])
            ->where('exam_id', $fixture['exam_id'])->delete();

        DB::table('exam_grading_snapshots')
            ->where('school_id', $fixture['school_id'])
            ->where('exam_id', $fixture['exam_id'])->delete();

        if (DB::table('learning_area_results')
            ->where('exam_id', $fixture['exam_id'])->exists()
            || DB::table('exam_grading_snapshots')
                ->where('exam_id', $fixture['exam_id'])->exists()) {
            throw new RuntimeException('First-time processing precondition failed.');
        }
    }

    DB::table('schools')->where('id', $fixture['school_id'])
        ->update(['school_name' => $marker]);

    $manifest = [
        'school_id' => $fixture['school_id'],
        'exam_id' => $fixture['exam_id'],
        'marker' => $marker,
        'role_id' => DB::table('users')->where('id', $fixture['actor_id'])->value('role_id'),
        'level_ids' => DB::table('grades')->where('school_id', $fixture['school_id'])
            ->pluck('education_level_id')->unique()->values()->all(),
        'area_ids' => DB::table('exam_learning_areas')->where('exam_id', $fixture['exam_id'])
            ->pluck('learning_area_id')->all(),
    ];

    if (file_put_contents(
        $manifestPath, json_encode($manifest, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT),
        LOCK_EX
    ) === false) {
        throw new RuntimeException('Cannot write fixture cleanup manifest.');
    }

    DB::commit();
    $committed = true;

    foreach (['learners', 'exam_roster_learners', 'learning_area_results',
        'exam_roster_papers', 'exam_results'] as $table) {
        DB::statement('ANALYZE '.$table);
    }

    $measurement = $meter->measure('full_processing', function () use ($fixture, $emit) {
        $processed = 0;
        foreach ($fixture['learner_ids'] as $index => $learnerId) {
            foreach ($fixture['exam_learning_area_ids'] as $areaId) {
                if (DB::transactionLevel() !== 0) {
                    throw new RuntimeException('Processing must start without an outer transaction.');
                }

                app(ResultProcessingService::class)->process(
                    $fixture['school_id'], $areaId, $learnerId, $fixture['actor_id']
                );
                $processed++;
            }

            if (($index + 1) % 250 === 0) {
                $emit(['operation' => 'progress', 'learners_processed' => $index + 1]);
            }
        }

        return ['learners' => count($fixture['learner_ids']), 'processed_operations' => $processed];
    });
    $emit($measurement);
    $failed = $measurement['status'] !== 'completed';

    if (! $failed) {
        $measurement = $meter->measure('completeness', function () use ($fixture) {
            $report = app(ExamCompletenessService::class)->report(
                $fixture['school_id'], $fixture['exam_id']
            );
            if (! $report['complete'] || $report['learner_count'] !== $fixture['learner_count']) {
                throw new RuntimeException('Processed fixture is incomplete.');
            }

            return ['complete' => true, 'learners' => $report['learner_count']];
        });
        $emit($measurement);
        $failed = $measurement['status'] !== 'completed';
    }
} catch (Throwable $exception) {
    $failed = true;
    $emit(['operation' => 'runner', 'status' => 'failed', 'error_type' => get_class($exception)]);
} finally {
    while (DB::transactionLevel() > 0) {
        DB::rollBack();
    }

    if ($committed) {
        try {
            DB::transaction(function () use ($manifest) {
                $schoolId = $manifest['school_id'];
                $examId = $manifest['exam_id'];

                $school = DB::table('schools')->where('id', $schoolId)->lockForUpdate()->first();
                if (! $school || $school->school_name !== $manifest['marker']) {
                    throw new RuntimeException('Fixture ownership verification failed.');
                }

                if (! DB::table('exams')->where('id', $examId)
                    ->where('school_id', $schoolId)->exists()) {
                    throw new RuntimeException('Fixture exam ownership verification failed.');
                }

                $cardIds = DB::table('report_cards')
                    ->where('exam_id', $examId)->pluck('id');
                DB::table('report_card_learning_areas')->whereIn('report_card_id', $cardIds)->delete();

                foreach (['report_cards', 'merit_lists', 'learning_area_results', 'exam_results',
                    'exam_rosters', 'exam_grading_snapshots', 'mark_entry_permissions'] as $table) {
                    DB::table($table)->where('exam_id', $examId)->delete();
                }

                $areaIds = DB::table('exam_learning_areas')
                    ->where('exam_id', $examId)->pluck('id');
                DB::table('exam_papers')->whereIn('exam_learning_area_id', $areaIds)->delete();
                DB::table('exam_learning_areas')->where('exam_id', $examId)->delete();
                DB::table('exams')->where('school_id', $schoolId)->where('id', $examId)->delete();

                $systems = DB::table('grading_systems')->where('school_id', $schoolId)->pluck('id');
                DB::table('grading_scales')->whereIn('grading_system_id', $systems)->delete();

                foreach (['grading_systems', 'learners', 'streams', 'grades',
                    'terms', 'academic_years', 'assessment_types', 'users'] as $table) {
                    DB::table($table)->where('school_id', $schoolId)->delete();
                }

                DB::table('schools')->where('id', $schoolId)->delete();
                DB::table('roles')->where('id', $manifest['role_id'])->delete();
                DB::table('education_levels')->whereIn('id', $manifest['level_ids'])->delete();
                DB::table('learning_areas')->whereIn('id', $manifest['area_ids'])->delete();
            });

            $remaining = DB::table('schools')->where('id', $manifest['school_id'])->count();
            if ($remaining !== 0) {
                throw new RuntimeException('Synthetic school remains.');
            }
            $emit(['operation' => 'cleanup', 'status' => 'completed', 'synthetic_school_remaining' => 0]);
            unlink($manifestPath);
        } catch (Throwable $exception) {
            $failed = true;
            $emit([
                'operation' => 'cleanup', 'status' => 'failed',
                'error_type' => get_class($exception),
                'manifest_path' => $manifestPath,
            ]);
        }
    } else {
        $emit(['operation' => 'cleanup', 'status' => 'rolled_back']);
        if (is_file($manifestPath)) {
            unlink($manifestPath);
        }
    }
}

echo 'RESULT_FILE='.$output.PHP_EOL;
exit($failed ? 1 : 0);
