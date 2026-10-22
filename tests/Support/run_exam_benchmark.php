<?php

use App\Models\User;
use App\Services\Assessment\ExamCompletenessService;
use App\Services\Assessment\ExamLearningAreaAnalysisService;
use App\Services\Assessment\MeritListService;
use App\Services\Assessment\ReportCardService;
use App\Services\Assessment\ResultProcessingService;
use App\Services\LeadershipPortal\LeadershipPortalAccessService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Tests\Support\ExamBenchmarkFixture;
use Tests\Support\ExamBenchmarkMeter;

require dirname(__DIR__, 2).'/vendor/autoload.php';

putenv('APP_ENV=testing');
$_ENV['APP_ENV'] = 'testing';
$_SERVER['APP_ENV'] = 'testing';

$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
if (is_file(dirname(__DIR__, 2).'/.env.testing')) {
    $app->loadEnvironmentFrom('.env.testing');
}
$app->make(Kernel::class)->bootstrap();

$count = filter_var($argv[1] ?? '100', FILTER_VALIDATE_INT);
$sample = filter_var($argv[2] ?? '10', FILTER_VALIDATE_INT);
$mode = $argv[3] ?? 'details';
if (! in_array($mode, ['details', 'summary'], true)) {
    fwrite(STDERR, "Response mode must be details or summary.\n");
    exit(1);
}

$database = DB::connection()->getDatabaseName();

if (! $app->environment('testing')
    || DB::connection()->getDriverName() !== 'pgsql'
    || ! preg_match('/(?:^|_)test(?:ing)?(?:_|$)/i', $database)
    || $count === false || $count < 1 || $count > 20000
    || $sample === false || $sample < 1 || $sample > $count
    || DB::transactionLevel() !== 0) {
    fwrite(STDERR, "Refused: requires a PostgreSQL testing database and valid learner/sample counts.\n");
    exit(1);
}

set_time_limit(900);
DB::connection()->disableQueryLog();
DB::statement("SET statement_timeout = '120s'");
DB::statement("SET lock_timeout = '5s'");

$output = storage_path('logs/exam-benchmark-'.$count.'-'.$mode.'-'.date('Ymd-His').'.jsonl');
$emit = function (array $row) use ($output): void {
    $json = json_encode($row, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    if (file_put_contents($output, $json.PHP_EOL, FILE_APPEND | LOCK_EX) === false) {
        throw new RuntimeException('Could not write benchmark results.');
    }
    echo json_encode([
        'operation' => $row['operation'] ?? 'metadata',
        'status' => $row['status'] ?? null,
        'elapsed_ms' => $row['elapsed_ms'] ?? null,
        'queries' => $row['query_count'] ?? null,
        'peak_memory_mb' => $row['peak_memory_mb'] ?? null,
        'summary' => $row['summary'] ?? null,
        'error_type' => $row['error_type'] ?? null,
    ], JSON_THROW_ON_ERROR).PHP_EOL;
    flush();
};

$emit([
    'operation' => 'metadata',
    'status' => 'started',
    'summary' => [
        'learners' => $count,
        'learning_areas' => 6,
        'papers_per_area' => 2,
        'processing_sample_learners' => $sample,
        'processing_sample_operations' => $sample * 6,
        'php_version' => PHP_VERSION,
        'postgres_version' => DB::selectOne('show server_version')->server_version,
        'php_memory_limit' => ini_get('memory_limit'),
        'fixture_mode' => 'direct_synthetic_rows',
        'production_roster_admission_limit' => 2000,
        'report_card_response_mode' => $mode,
        'analysis_access' => 'stubbed_school_scope_not_authorization_benchmark',
        'transaction_mode' => 'single_outer_transaction_rolled_back',
        'timing_scope' => 'service_calls_without_http_or_pdf_rendering',
    ],
]);

$meter = new ExamBenchmarkMeter;
$failed = false;
$fixture = null;
DB::beginTransaction();

try {
    $measurement = $meter->measure('fixture_setup', function () use ($count, &$fixture) {
        $fixture = ExamBenchmarkFixture::create($count);

        return [
            'learners' => $count,
            'paper_results' => $count * 12,
            'processed_results' => $count * 6,
        ];
    });
    $emit($measurement);
    if ($measurement['status'] !== 'completed') {
        throw new RuntimeException('Fixture setup failed.');
    }

    $actor = User::withoutGlobalScopes()->findOrFail($fixture['actor_id']);
    // Synthetic bulk inserts need planner statistics before measurement.
    $statistics = $meter->measure('planner_statistics', function () {
        foreach ([
            'learners',
            'exam_roster_learners',
            'learning_area_results',
            'exam_roster_papers',
            'exam_results',
        ] as $table) {
            DB::statement('ANALYZE '.$table);
        }

        return ['tables_analyzed' => 5];
    });
    $emit($statistics);
    if ($statistics['status'] !== 'completed') {
        throw new RuntimeException('Benchmark statistics refresh failed.');
    }

    $access = Mockery::mock(LeadershipPortalAccessService::class);
    $access->shouldReceive('require')->with($actor, 'view_academic_insights')->andReturnNull();
    $access->shouldReceive('scope')->with($actor)->andReturn([
        'school_id' => $fixture['school_id'],
        'role_key' => 'head_teacher',
        'executive_summary_only' => false,
        'learning_area_ids' => [],
    ]);
    $analysis = new ExamLearningAreaAnalysisService($access);

    $operations = [
        'processing_sample' => function () use ($fixture, $sample) {
            $processed = 0;
            foreach (array_slice($fixture['learner_ids'], 0, $sample) as $learnerId) {
                foreach ($fixture['exam_learning_area_ids'] as $areaId) {
                    app(ResultProcessingService::class)->process(
                        $fixture['school_id'], $areaId, $learnerId, $fixture['actor_id']
                    );
                    $processed++;
                }
            }

            return ['sample_learners' => $sample, 'processed_operations' => $processed];
        },
        'completeness' => function () use ($fixture) {
            $report = app(ExamCompletenessService::class)->report(
                $fixture['school_id'], $fixture['exam_id']
            );
            if (! $report['complete'] || $report['learner_count'] !== $fixture['learner_count']) {
                throw new RuntimeException('Synthetic fixture is not complete.');
            }

            return ['complete' => true, 'learners' => $report['learner_count']];
        },
        'analysis' => function () use ($analysis, $actor, $fixture) {
            $result = $analysis->analyse($actor, $fixture['exam_id'], ['top_limit' => 10]);

            return [
                'assessed_learners' => $result['assessed_learners'],
                'learning_areas' => count($result['learning_areas']),
            ];
        },
        'merit_generation' => fn () => [
            'rows' => app(MeritListService::class)->generate(
                $fixture['school_id'], $fixture['exam_id'], null, null, $fixture['actor_id']
            )->count(),
        ],
        'report_card_generation_'.$mode => function () use ($fixture, $mode) {
            $service = app(ReportCardService::class);

            if ($mode === 'summary') {
                $summary = $service->generateSummary(
                    $fixture['school_id'], $fixture['exam_id'],
                    null, null, null, $fixture['actor_id']
                );
                $rows = $summary['generated_count'];
            } else {
                $rows = $service->generate(
                    $fixture['school_id'], $fixture['exam_id'],
                    null, null, null, $fixture['actor_id']
                )->count();
            }

            if ($rows !== $fixture['learner_count']) {
                throw new RuntimeException('Generated card count is incorrect.');
            }

            return ['rows' => $rows, 'response_mode' => $mode];
        },
    ];

    // Reuse the same fixture to measure existing-row paths separately.
    $operations['report_card_regeneration_'.$mode] =
        $operations['report_card_generation_'.$mode];

    $operations['merit_regeneration'] = $operations['merit_generation'];

    foreach ($operations as $name => $operation) {
        echo 'START '.$name.PHP_EOL;
        flush();
        $measurement = $meter->measure(
            $name, fn () => DB::transaction($operation)
        );
        $emit($measurement);
        if ($measurement['status'] !== 'completed') {
            $failed = true;
            break;
        }
    }
} catch (Throwable $exception) {
    $failed = true;
    $emit([
        'operation' => 'runner',
        'status' => 'failed',
        'error_type' => get_class($exception),
    ]);
} finally {
    while (DB::transactionLevel() > 0) {
        DB::rollBack();
    }
    Mockery::close();
}

$remaining = $fixture === null ? 0 : DB::table('schools')
    ->where('id', $fixture['school_id'])->count();
$emit([
    'operation' => 'cleanup',
    'status' => $remaining === 0 ? 'rolled_back' : 'failed',
    'summary' => ['synthetic_school_remaining' => $remaining],
]);
echo 'RESULT_FILE='.$output.PHP_EOL;
exit($failed || $remaining !== 0 ? 1 : 0);
