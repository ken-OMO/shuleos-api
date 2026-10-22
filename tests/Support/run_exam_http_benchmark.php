<?php

use App\Models\User;
use App\Services\Assessment\ExamCompletenessService;
use App\Services\Assessment\MeritListService;
use App\Services\Assessment\ReportCardService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
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
$marker = 'HTTP benchmark '.$run;
$output = storage_path('logs/exam-http-'.$count.'-'.$run.'.jsonl');
$manifestPath = storage_path('logs/exam-http-'.$run.'-fixture.json');
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
        'transaction_mode' => 'committed_fixture_individual_service_transactions',
        'scope' => 'authenticated_http_kernel_json_and_sampled_pdf',
        'fixture_results' => 'synthetic_already_processed',
        'report_card_response_mode' => 'summary',
        'production_roster_admission_limit' => 2000,
    ]);

    DB::beginTransaction();
    $fixture = ExamBenchmarkFixture::create($count);

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

    $backendPid = (int) DB::selectOne('select pg_backend_pid() as pid')->pid;

    foreach (['merit_generation', 'report_card_generation'] as $operation) {
        $measurement = $meter->measure($operation, function () use (
            $fixture, $operation, $backendPid
        ) {
            if (DB::transactionLevel() !== 0) {
                throw new RuntimeException('Generation requires no outer transaction.');
            }

            $started = microtime(true);

            if ($operation === 'merit_generation') {
                $outputs = app(MeritListService::class)->generate(
                    $fixture['school_id'], $fixture['exam_id'],
                    null, null, $fixture['actor_id']
                );
                $generated = $outputs->count();
                unset($outputs);
            } else {
                $summary = app(ReportCardService::class)->generateSummary(
                    $fixture['school_id'], $fixture['exam_id'],
                    null, null, null, $fixture['actor_id']
                );
                $generated = $summary['generated_count'];

                if ($summary['exam_id'] !== $fixture['exam_id']
                    || $summary['status'] !== 'generated') {
                    throw new RuntimeException('Unexpected report-card summary.');
                }
            }

            $finished = microtime(true);

            if ($generated !== $fixture['learner_count']) {
                throw new RuntimeException('Generated output count mismatch.');
            }

            return [
                'learners' => $fixture['learner_count'],
                'generated_count' => $generated,
                'school_id' => $fixture['school_id'],
                'exam_id' => $fixture['exam_id'],
                'backend_pid' => $backendPid,
                'started_unix' => $started,
                'finished_unix' => $finished,
            ];
        });
        $emit($measurement);

        if ($measurement['status'] !== 'completed') {
            $failed = true;
            break;
        }
    }

    if (! $failed) {
        config(['jwt.secret' => str_repeat('assessment-http-benchmark-', 3)]);

        $permission = DB::table('permissions')
            ->where('permission_name', 'generate_report_cards')->value('id');
        if (! $permission) {
            throw new RuntimeException('Report-card permission migration is required.');
        }

        $user = User::withoutGlobalScopes()
            ->findOrFail($fixture['actor_id'])->load('role');

        DB::table('role_permissions')->insert([
            'id' => (string) Str::uuid(),
            'role_id' => $user->role_id,
            'permission_id' => $permission,
            'created_at' => now(),
        ]);

        $token = JWTAuth::fromUser($user);
        $http = app(Illuminate\Contracts\Http\Kernel::class);

        $request = function (
            string $method, string $path, ?array $payload = null,
            bool $authenticated = true
        ) use ($http, $token, $emit) {
            // JWTAuth retains the guard instance; clear its user between requests.
            auth()->guard()->forgetUser();

            $server = [
                'HTTP_ACCEPT' => 'application/json',
                'CONTENT_TYPE' => 'application/json',
            ];
            if ($authenticated) {
                $server['HTTP_AUTHORIZATION'] = 'Bearer '.$token;
            }

            $request = Request::create(
                'http://localhost'.$path, $method, [], [], [], $server,
                $payload === null ? null : json_encode($payload, JSON_THROW_ON_ERROR)
            );

            JWTAuth::setRequest($request);
            $response = $http->handle($request);

            if ($authenticated && $response->getStatusCode() !== 200) {
                $emit([
                    'operation' => 'http_response_diagnostic',
                    'http_status' => $response->getStatusCode(),
                    'method' => $method,
                    'path' => $path,
                    'authorization_header_present' => $request->hasHeader('Authorization'),
                    'request_user_present' => $request->user() !== null,
                    'guard_user_present' => auth()->user() !== null,
                    'tenant_context_present' => $request->attributes->has('tenant_school_id'),
                ]);
            }

            try {
                // Force response content to materialize before termination.
                $response->getContent();
            } finally {
                $http->terminate($request, $response);
            }

            return $response;
        };

        $unauthenticated = $request('POST', '/api/report-cards/generate', [
            'exam_id' => $fixture['exam_id'],
            'response_mode' => 'summary',
        ], false);

        if ($unauthenticated->getStatusCode() !== 401) {
            throw new RuntimeException('Unauthenticated HTTP request was not rejected.');
        }
        unset($unauthenticated);

        $cards = DB::table('report_cards')
            ->where('school_id', $fixture['school_id'])
            ->where('exam_id', $fixture['exam_id'])
            ->orderBy('learner_id')->get(['id', 'learner_id']);

        $pdfCards = [];
        $sampleSize = min(10, $cards->count());
        for ($index = 0; $index < $sampleSize; $index++) {
            $position = $sampleSize === 1 ? 0
                : (int) floor($index * ($cards->count() - 1) / ($sampleSize - 1));
            $pdfCards[] = $cards[$position];
        }
        unset($cards);

        foreach (['http_summary', 'http_details', 'http_pdf_sample'] as $operation) {
            $measurement = $meter->measure($operation, function () use (
                $fixture, $operation, $backendPid, $request, $pdfCards
            ) {
                $started = microtime(true);
                $bytes = 0;
                $requests = 0;

                if ($operation === 'http_pdf_sample') {
                    foreach ($pdfCards as $index => $card) {
                        $download = $index === count($pdfCards) - 1;
                        $path = '/api/report-cards/'.$card->id.'/pdf'
                            .($download ? '/download' : '');
                        $response = $request('GET', $path);
                        $content = $response->getContent();

                        if ($response->getStatusCode() !== 200
                            || $response->headers->get('content-type') !== 'application/pdf'
                            || ! str_starts_with($content, '%PDF')
                            || ! str_contains(substr($content, -1024), '%%EOF')
                            || ! str_contains(
                                $response->headers->get('content-disposition', ''),
                                $download ? 'attachment' : 'inline'
                            )) {
                            throw new RuntimeException('PDF status, headers or structure failed validation.');
                        }

                        $bytes += strlen($content);
                        $requests++;
                        unset($response, $content);
                        gc_collect_cycles();
                    }
                } else {
                    $mode = $operation === 'http_summary' ? 'summary' : 'details';
                    $response = $request('POST', '/api/report-cards/generate', [
                        'exam_id' => $fixture['exam_id'],
                        'response_mode' => $mode,
                    ]);

                    if ($response->getStatusCode() !== 200) {
                        throw new RuntimeException(
                            'Generation HTTP status: '.$response->getStatusCode()
                        );
                    }

                    $content = $response->getContent();
                    $bytes = strlen($content);
                    $data = json_decode($content, true, 512, JSON_THROW_ON_ERROR)['data'];
                    $requests = 1;

                    if ($mode === 'summary') {
                        if ($data !== [
                            'exam_id' => $fixture['exam_id'],
                            'generated_count' => $fixture['learner_count'],
                            'status' => 'generated',
                        ]) {
                            throw new RuntimeException('HTTP summary response mismatch.');
                        }
                    } else {
                        $actual = [];
                        foreach ($data as $card) {
                            if ($card['school_id'] !== $fixture['school_id']
                                || $card['exam_id'] !== $fixture['exam_id']
                                || $card['status'] !== 'generated'
                                || count($card['learning_areas']) !== 6
                                || count(array_unique(array_column(
                                    $card['learning_areas'], 'learning_area_id'
                                ))) !== 6) {
                                throw new RuntimeException('HTTP detail ownership or area coverage mismatch.');
                            }
                            $actual[] = $card['learner_id'];
                        }

                        sort($actual);
                        $expected = $fixture['learner_ids'];
                        sort($expected);
                        if ($actual !== $expected) {
                            throw new RuntimeException('HTTP detail learner coverage mismatch.');
                        }
                    }
                    unset($response, $content, $data);
                }

                return [
                    'learners' => $fixture['learner_count'],
                    'school_id' => $fixture['school_id'],
                    'exam_id' => $fixture['exam_id'],
                    'backend_pid' => $backendPid,
                    'started_unix' => $started,
                    'finished_unix' => microtime(true),
                    'requests' => $requests,
                    'response_bytes' => $bytes,
                    'response_validation_passed' => true,
                    'middleware_enabled' => true,
                ];
            });
            $emit($measurement);

            if ($measurement['status'] !== 'completed') {
                $failed = true;
                break;
            }
        }
    }

    if (! $failed) {
        $measurement = $meter->measure('completeness', function () use ($fixture) {
            $schoolId = $fixture['school_id'];
            $examId = $fixture['exam_id'];
            $expected = $fixture['learner_count'];

            $report = app(ExamCompletenessService::class)->report($schoolId, $examId);
            if (! $report['complete'] || $report['learner_count'] !== $expected) {
                throw new RuntimeException('Source fixture is incomplete.');
            }

            foreach (['merit_lists', 'report_cards'] as $table) {
                $rows = DB::table($table)->where('exam_id', $examId);

                if ((clone $rows)->count() !== $expected
                    || (clone $rows)->where('school_id', '!=', $schoolId)->exists()
                    || (clone $rows)->where('is_deleted', true)->exists()
                    || (clone $rows)->where('status', '!=', 'generated')->exists()) {
                    throw new RuntimeException('Output count, state or ownership mismatch.');
                }

                $ids = (clone $rows)->pluck('learner_id')->sort()->values()->all();
                $expectedIds = collect($fixture['learner_ids'])->sort()->values()->all();
                if ($ids !== $expectedIds) {
                    throw new RuntimeException('Output learner coverage mismatch.');
                }
            }

            $details = DB::table('report_card_learning_areas as details')
                ->join('report_cards as cards', 'cards.id', '=', 'details.report_card_id')
                ->where('cards.exam_id', $examId);

            if ((clone $details)->count() !== $expected * 6
                || (clone $details)->where('details.is_deleted', true)->exists()) {
                throw new RuntimeException('Report-card detail coverage mismatch.');
            }

            $coverage = (clone $details)
                ->select('cards.learner_id')
                ->groupBy('cards.learner_id')
                ->havingRaw('count(*) <> 6 OR count(distinct details.learning_area_id) <> 6')
                ->get();

            if ($coverage->isNotEmpty()) {
                throw new RuntimeException('Per-card learning-area coverage mismatch.');
            }

            return [
                'complete' => true,
                'learners' => $expected,
                'processed_results' => $expected * 6,
                'merits' => $expected,
                'report_cards' => $expected,
                'report_card_details' => $expected * 6,
                'school_isolation_verified' => true,
            ];
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
                DB::table('role_permissions')->where('role_id', $manifest['role_id'])->delete();
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
