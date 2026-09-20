<?php

declare(strict_types=1);

namespace Tests\Feature\TeacherDuty;

use App\Models\Role;
use App\Models\School;
use App\Models\Teacher;
use App\Models\TeacherDutyAssignment;
use App\Models\TeacherDutyPeriod;
use App\Models\User;
use App\Services\TeacherDuty\TeacherDutyDailyReportService;
use App\Services\TeacherDuty\TeacherDutyRosterService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Tests\Support\Database\TeacherBuilder;
use Tests\TestCase;

final class TeacherDutyDailyReportManagementTest extends TestCase
{
    use DatabaseTransactions;

    private School $school;

    private User $reporter;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'jwt.secret' => str_repeat('a', 64),
        ]);

        $this->school = $this->createSchool();
        $this->completeOperationalSetup($this->school);
        $this->createSettings($this->school);

        $this->reporter = $this->createUser($this->school);
        TeacherBuilder::create($this->school, $this->reporter);

        $this->grantPermission(
            $this->reporter,
            'submit_teacher_duty_reports'
        );
    }

    public function test_routes_have_frozen_security_contract(): void
    {
        $routes = collect(Route::getRoutes()->getRoutes());

        $contracts = [
            [
                'POST',
                'api/teacher-duty/periods/{period}/daily-reports/open',
                true,
            ],
            [
                'GET',
                'api/teacher-duty/periods/{period}/daily-reports/state',
                false,
            ],
            [
                'PATCH',
                'api/teacher-duty/daily-reports/{report}',
                true,
            ],
            [
                'POST',
                'api/teacher-duty/daily-reports/{report}/submit',
                true,
            ],
        ];

        foreach ($contracts as [$method, $uri, $operational]) {
            $route = $routes->first(
                fn ($candidate): bool => in_array(
                    $method,
                    $candidate->methods(),
                    true
                ) && $candidate->uri() === $uri
            );

            $this->assertNotNull(
                $route,
                "{$method} {$uri} must be registered."
            );

            $middleware = $route->gatherMiddleware();

            $this->assertContains(
                'permission:submit_teacher_duty_reports',
                $middleware
            );

            $this->assertNotContains(
                'permission:manage_teacher_duty_roster',
                $middleware
            );

            if ($operational) {
                $this->assertContains(
                    'school.operational',
                    $middleware
                );
            } else {
                $this->assertNotContains(
                    'school.operational',
                    $middleware
                );
            }
        }
    }

    public function test_reporter_can_open_update_submit_and_read_state(): void
    {
        $period = $this->createDutyPeriod($this->reporter);
        $this->assignReporter($period, $this->reporter);

        $this->actingAsJwt($this->reporter);

        $open = $this->postJson(
            "/api/teacher-duty/periods/{$period->id}/daily-reports/open",
            [
                'report_date' => '2026-09-09',
            ]
        );

        $open
            ->assertCreated()
            ->assertJsonPath(
                'data.duty_period_id',
                (string) $period->id
            )
            ->assertJsonPath(
                'data.report_date',
                '2026-09-09'
            )
            ->assertJsonPath(
                'data.status',
                'draft'
            )
            ->assertJsonPath(
                'data.summary',
                null
            )
            ->assertJsonPath(
                'data.created_by',
                (string) $this->reporter->id
            );

        $reportId = (string) $open->json('data.id');

        $update = $this->patchJson(
            "/api/teacher-duty/daily-reports/{$reportId}",
            [
                'summary' => 'Normal teacher duty day.',
            ]
        );

        $update
            ->assertOk()
            ->assertJsonPath(
                'data.status',
                'draft'
            )
            ->assertJsonPath(
                'data.summary',
                'Normal teacher duty day.'
            );

        $submit = $this->postJson(
            "/api/teacher-duty/daily-reports/{$reportId}/submit",
            []
        );

        $submit
            ->assertOk()
            ->assertJsonPath(
                'data.status',
                'submitted'
            )
            ->assertJsonPath(
                'data.submitted_by',
                (string) $this->reporter->id
            );

        $this->assertNotNull(
            $submit->json('data.submitted_at')
        );

        $state = $this->getJson(
            "/api/teacher-duty/periods/{$period->id}/daily-reports/state".
            '?report_date=2026-09-09'
        );

        $state
            ->assertOk()
            ->assertJsonPath(
                'data.state',
                'SUBMITTED'
            )
            ->assertJsonPath(
                'data.submitted_at',
                $submit->json('data.submitted_at')
            );

        $this->assertDatabaseHas(
            'teacher_duty_daily_reports',
            [
                'id' => $reportId,
                'school_id' => $this->school->id,
                'duty_period_id' => $period->id,
                'status' => 'submitted',
                'summary' => 'Normal teacher duty day.',
                'created_by' => $this->reporter->id,
                'submitted_by' => $this->reporter->id,
            ]
        );

        $this->assertDatabaseHas(
            'teacher_duty_daily_report_history',
            [
                'daily_report_id' => $reportId,
                'actor_user_id' => $this->reporter->id,
                'from_status' => null,
                'to_status' => 'draft',
                'event' => 'created',
            ]
        );

        $this->assertDatabaseHas(
            'teacher_duty_daily_report_history',
            [
                'daily_report_id' => $reportId,
                'actor_user_id' => $this->reporter->id,
                'from_status' => 'draft',
                'to_status' => 'submitted',
                'event' => 'submitted',
            ]
        );
    }

    public function test_open_is_idempotent_without_duplicate_history_or_audit(): void
    {
        $period = $this->createDutyPeriod($this->reporter);
        $this->assignReporter($period, $this->reporter);

        $this->actingAsJwt($this->reporter);

        $url =
            "/api/teacher-duty/periods/{$period->id}/daily-reports/open";

        $first = $this->postJson($url, [
            'report_date' => '2026-09-09',
        ]);

        $first->assertCreated();

        $reportId = (string) $first->json('data.id');

        $second = $this->postJson($url, [
            'report_date' => '2026-09-09',
        ]);

        $second
            ->assertOk()
            ->assertJsonPath('data.id', $reportId)
            ->assertJsonPath('data.status', 'draft');

        $this->assertSame(
            1,
            DB::table('teacher_duty_daily_reports')
                ->where('school_id', $this->school->id)
                ->where('duty_period_id', $period->id)
                ->where('report_date', '2026-09-09')
                ->count()
        );

        $this->assertSame(
            1,
            DB::table('teacher_duty_daily_report_history')
                ->where('daily_report_id', $reportId)
                ->where('event', 'created')
                ->count()
        );

        $this->assertSame(
            1,
            DB::table('audit_logs')
                ->where('school_id', $this->school->id)
                ->where('user_id', $this->reporter->id)
                ->where('module', 'Teacher Duty Daily Reports')
                ->where('action', 'Create')
                ->where(
                    'table_name',
                    'teacher_duty_daily_reports'
                )
                ->where('record_id', $reportId)
                ->count()
        );
    }

    public function test_state_does_not_materialize_report_history_or_audit(): void
    {
        $period = $this->createDutyPeriod($this->reporter);
        $this->assignReporter($period, $this->reporter);

        $this->actingAsJwt($this->reporter);

        $reportCount = DB::table(
            'teacher_duty_daily_reports'
        )->count();

        $historyCount = DB::table(
            'teacher_duty_daily_report_history'
        )->count();

        $auditCount = DB::table('audit_logs')->count();

        $response = $this->getJson(
            "/api/teacher-duty/periods/{$period->id}/daily-reports/state".
            '?report_date=2026-09-09'
        );

        $response
            ->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'state',
                    'deadline_at',
                    'submitted_at',
                    'late',
                ],
            ]);

        $this->assertContains(
            $response->json('data.state'),
            [
                'NOT_STARTED',
                'OVERDUE',
            ]
        );

        $this->assertSame(
            $reportCount,
            DB::table('teacher_duty_daily_reports')->count()
        );

        $this->assertSame(
            $historyCount,
            DB::table(
                'teacher_duty_daily_report_history'
            )->count()
        );

        $this->assertSame(
            $auditCount,
            DB::table('audit_logs')->count()
        );
    }

    public function test_all_daily_operations_reject_reporter_without_period_responsibility(): void
    {
        $period = $this->createDutyPeriod($this->reporter);
        $this->assignReporter($period, $this->reporter);

        $this->actingAsJwt($this->reporter);

        $open = $this->postJson(
            "/api/teacher-duty/periods/{$period->id}/daily-reports/open",
            [
                'report_date' => '2026-09-09',
            ]
        );

        $open->assertCreated();

        $reportId = (string) $open->json('data.id');

        $unauthorizedReporter = $this->createUser($this->school);

        TeacherBuilder::create(
            $this->school,
            $unauthorizedReporter
        );

        $this->grantPermission(
            $unauthorizedReporter,
            'submit_teacher_duty_reports'
        );

        $this->actingAsJwt($unauthorizedReporter);

        $openAttempt = $this->postJson(
            "/api/teacher-duty/periods/{$period->id}/daily-reports/open",
            [
                'report_date' => '2026-09-10',
            ]
        );

        $openAttempt
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['actor']);

        $stateAttempt = $this->getJson(
            "/api/teacher-duty/periods/{$period->id}/daily-reports/state".
            '?report_date=2026-09-09'
        );

        $stateAttempt
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['actor']);

        $updateAttempt = $this->patchJson(
            "/api/teacher-duty/daily-reports/{$reportId}",
            [
                'summary' => 'Unauthorized change.',
            ]
        );

        $updateAttempt
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['actor']);

        $submitAttempt = $this->postJson(
            "/api/teacher-duty/daily-reports/{$reportId}/submit",
            []
        );

        $submitAttempt
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['actor']);

        $this->assertDatabaseHas(
            'teacher_duty_daily_reports',
            [
                'id' => $reportId,
                'status' => 'draft',
                'summary' => null,
                'submitted_by' => null,
                'submitted_at' => null,
            ]
        );

        $this->assertDatabaseMissing(
            'teacher_duty_daily_reports',
            [
                'school_id' => $this->school->id,
                'duty_period_id' => $period->id,
                'report_date' => '2026-09-10',
            ]
        );
    }

    public function test_ended_assignment_authorizes_all_daily_operations(): void
    {
        $period = $this->createDutyPeriod($this->reporter);

        $assignment = $this->assignReporter(
            $period,
            $this->reporter
        );

        app(TeacherDutyRosterService::class)
            ->endAssignment(
                (string) $this->school->id,
                (string) $assignment->id,
                (string) $this->reporter->id,
                'Historical assignment'
            );

        $this->assertDatabaseHas(
            'teacher_duty_assignments',
            [
                'id' => $assignment->id,
                'school_id' => $this->school->id,
                'duty_period_id' => $period->id,
                'active' => false,
                'end_reason' => 'Historical assignment',
            ]
        );

        $this->actingAsJwt($this->reporter);

        $open = $this->postJson(
            "/api/teacher-duty/periods/{$period->id}/daily-reports/open",
            [
                'report_date' => '2026-09-09',
            ]
        );

        $open
            ->assertCreated()
            ->assertJsonPath('data.status', 'draft');

        $reportId = (string) $open->json('data.id');

        $state = $this->getJson(
            "/api/teacher-duty/periods/{$period->id}/daily-reports/state".
            '?report_date=2026-09-09'
        );

        $state
            ->assertOk()
            ->assertJsonPath(
                'data.state',
                'OVERDUE'
            );

        $update = $this->patchJson(
            "/api/teacher-duty/daily-reports/{$reportId}",
            [
                'summary' => 'Historical duty responsibility.',
            ]
        );

        $update
            ->assertOk()
            ->assertJsonPath(
                'data.summary',
                'Historical duty responsibility.'
            );

        $submit = $this->postJson(
            "/api/teacher-duty/daily-reports/{$reportId}/submit",
            []
        );

        $submit
            ->assertOk()
            ->assertJsonPath('data.status', 'submitted')
            ->assertJsonPath(
                'data.submitted_by',
                (string) $this->reporter->id
            );
    }

    public function test_malformed_daily_route_identifiers_fail_as_generic_422_without_database_leak(): void
    {
        $this->actingAsJwt($this->reporter);

        $attempts = [
            [
                'POST',
                '/api/teacher-duty/periods/not-a-uuid/daily-reports/open',
                [
                    'report_date' => '2026-09-09',
                ],
            ],
            [
                'GET',
                '/api/teacher-duty/periods/not-a-uuid/daily-reports/state'.
                    '?report_date=2026-09-09',
                null,
            ],
            [
                'PATCH',
                '/api/teacher-duty/daily-reports/not-a-uuid',
                [
                    'summary' => 'Malformed resource probe.',
                ],
            ],
            [
                'POST',
                '/api/teacher-duty/daily-reports/not-a-uuid/submit',
                [],
            ],
        ];

        foreach ($attempts as [$method, $uri, $payload]) {
            $response = match ($method) {
                'GET' => $this->getJson($uri),
                'PATCH' => $this->patchJson($uri, $payload),
                'POST' => $this->postJson($uri, $payload),
            };

            $response
                ->assertUnprocessable()
                ->assertJsonValidationErrors([
                    'resource',
                ]);

            $body = strtolower($response->getContent());

            foreach ([
                'sqlstate',
                'postgres',
                'invalid input syntax',
                'queryexception',
                'pdoexception',
                'stack trace',
                'vendor/',
            ] as $leak) {
                $this->assertStringNotContainsString(
                    $leak,
                    $body
                );
            }
        }
    }

    public function test_cross_tenant_daily_period_and_report_resources_fail_closed(): void
    {
        $foreignSchool = $this->createSchool();

        $this->completeOperationalSetup($foreignSchool);
        $this->createSettings($foreignSchool);

        $foreignReporter = $this->createUser($foreignSchool);

        TeacherBuilder::create(
            $foreignSchool,
            $foreignReporter
        );

        $this->grantPermission(
            $foreignReporter,
            'submit_teacher_duty_reports'
        );

        $foreignPeriod = $this->createDutyPeriod(
            $foreignReporter
        );

        $this->assignReporter(
            $foreignPeriod,
            $foreignReporter
        );

        $this->actingAsJwt($foreignReporter);

        $foreignOpen = $this->postJson(
            "/api/teacher-duty/periods/{$foreignPeriod->id}/daily-reports/open",
            [
                'report_date' => '2026-09-09',
            ]
        );

        $foreignOpen->assertCreated();

        $foreignReportId = (string) $foreignOpen->json(
            'data.id'
        );

        $this->actingAsJwt($this->reporter);

        $attempts = [
            [
                'POST',
                "/api/teacher-duty/periods/{$foreignPeriod->id}/daily-reports/open",
                [
                    'report_date' => '2026-09-10',
                ],
            ],
            [
                'GET',
                "/api/teacher-duty/periods/{$foreignPeriod->id}/daily-reports/state".
                    '?report_date=2026-09-09',
                null,
            ],
            [
                'PATCH',
                "/api/teacher-duty/daily-reports/{$foreignReportId}",
                [
                    'summary' => 'Cross-tenant mutation probe.',
                ],
            ],
            [
                'POST',
                "/api/teacher-duty/daily-reports/{$foreignReportId}/submit",
                [],
            ],
        ];

        foreach ($attempts as [$method, $uri, $payload]) {
            $response = match ($method) {
                'GET' => $this->getJson($uri),
                'PATCH' => $this->patchJson($uri, $payload),
                'POST' => $this->postJson($uri, $payload),
            };

            $response
                ->assertUnprocessable()
                ->assertJsonValidationErrors([
                    'resource',
                ]);

            $body = strtolower($response->getContent());

            foreach ([
                'sqlstate',
                'postgres',
                'invalid input syntax',
                'queryexception',
                'pdoexception',
                'stack trace',
                'vendor/',
                strtolower((string) $foreignSchool->id),
            ] as $leak) {
                $this->assertStringNotContainsString(
                    $leak,
                    $body
                );
            }
        }

        $this->assertDatabaseHas(
            'teacher_duty_daily_reports',
            [
                'id' => $foreignReportId,
                'school_id' => $foreignSchool->id,
                'duty_period_id' => $foreignPeriod->id,
                'status' => 'draft',
                'summary' => null,
                'submitted_by' => null,
                'submitted_at' => null,
            ]
        );

        $this->assertDatabaseMissing(
            'teacher_duty_daily_reports',
            [
                'school_id' => $foreignSchool->id,
                'duty_period_id' => $foreignPeriod->id,
                'report_date' => '2026-09-10',
            ]
        );
    }

    public function test_open_and_state_enforce_strict_report_date_and_period_bounds(): void
    {
        $period = $this->createDutyPeriod($this->reporter);
        $this->assignReporter($period, $this->reporter);

        $this->actingAsJwt($this->reporter);

        $openUrl =
            "/api/teacher-duty/periods/{$period->id}/daily-reports/open";

        $stateUrl =
            "/api/teacher-duty/periods/{$period->id}/daily-reports/state";

        foreach ([
            '2026-9-9',
            '2026-09-09T00:00:00',
            'not-a-date',
        ] as $invalidDate) {
            $open = $this->postJson($openUrl, [
                'report_date' => $invalidDate,
            ]);

            $open
                ->assertUnprocessable()
                ->assertJsonValidationErrors([
                    'report_date',
                ]);

            $state = $this->getJson(
                $stateUrl.
                '?report_date='.
                rawurlencode($invalidDate)
            );

            $state
                ->assertUnprocessable()
                ->assertJsonValidationErrors([
                    'report_date',
                ]);
        }

        foreach ([
            '2026-09-06',
            '2026-09-12',
        ] as $outsideDate) {
            $open = $this->postJson($openUrl, [
                'report_date' => $outsideDate,
            ]);

            $open
                ->assertUnprocessable()
                ->assertJsonValidationErrors([
                    'report_date',
                ]);

            $state = $this->getJson(
                $stateUrl.
                '?report_date='.
                $outsideDate
            );

            $state
                ->assertUnprocessable()
                ->assertJsonValidationErrors([
                    'report_date',
                ]);
        }

        foreach ([
            '2026-09-07',
            '2026-09-11',
        ] as $boundaryDate) {
            $state = $this->getJson(
                $stateUrl.
                '?report_date='.
                $boundaryDate
            );

            $state
                ->assertOk()
                ->assertJsonStructure([
                    'data' => [
                        'state',
                        'deadline_at',
                        'submitted_at',
                        'late',
                    ],
                ]);
        }

        $this->assertSame(
            0,
            DB::table('teacher_duty_daily_reports')->count()
        );

        $this->assertSame(
            0,
            DB::table(
                'teacher_duty_daily_report_history'
            )->count()
        );
    }

    public function test_daily_requests_reject_client_control_of_server_owned_fields(): void
    {
        $period = $this->createDutyPeriod($this->reporter);
        $this->assignReporter($period, $this->reporter);

        $this->actingAsJwt($this->reporter);

        $openUrl =
            "/api/teacher-duty/periods/{$period->id}/daily-reports/open";

        $openOwnedFields = [
            'duty_period_id' => (string) Str::uuid(),
            'status' => 'submitted',
            'summary' => 'Client-owned summary.',
            'deadline_at' => '2026-09-09T12:00:00+03:00',
            'created_by' => (string) Str::uuid(),
            'submitted_by' => (string) Str::uuid(),
            'submitted_at' => '2026-09-09T12:00:00+03:00',
            'late' => true,
            'state' => 'SUBMITTED',
            'actor_user_id' => (string) Str::uuid(),
            'user_id' => (string) Str::uuid(),
        ];

        foreach ($openOwnedFields as $field => $value) {
            $response = $this->postJson(
                $openUrl,
                [
                    'report_date' => '2026-09-09',
                    $field => $value,
                ]
            );

            $response
                ->assertUnprocessable()
                ->assertJsonValidationErrors([$field]);
        }

        $schoolIdAttempt = $this->postJson(
            $openUrl,
            [
                'report_date' => '2026-09-09',
                'school_id' => (string) $this->school->id,
            ]
        );

        $schoolIdAttempt
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['school_id']);

        $stateSchoolIdAttempt = $this->getJson(
            "/api/teacher-duty/periods/{$period->id}/daily-reports/state".
            '?report_date=2026-09-09'.
            '&school_id='.
            rawurlencode((string) $this->school->id)
        );

        $stateSchoolIdAttempt
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['school_id']);

        foreach ([
            'actor_user_id',
            'user_id',
        ] as $field) {
            $response = $this->getJson(
                "/api/teacher-duty/periods/{$period->id}/daily-reports/state".
                '?report_date=2026-09-09'.
                '&'.$field.'='.
                rawurlencode((string) Str::uuid())
            );

            $response
                ->assertUnprocessable()
                ->assertJsonValidationErrors([$field]);
        }

        $validOpen = $this->postJson(
            $openUrl,
            [
                'report_date' => '2026-09-09',
            ]
        );

        $validOpen->assertCreated();

        $reportId = (string) $validOpen->json('data.id');

        $updateOwnedFields = [
            'duty_period_id' => (string) Str::uuid(),
            'report_date' => '2026-09-10',
            'status' => 'submitted',
            'deadline_at' => '2026-09-09T12:00:00+03:00',
            'created_by' => (string) Str::uuid(),
            'submitted_by' => (string) Str::uuid(),
            'submitted_at' => '2026-09-09T12:00:00+03:00',
            'late' => true,
            'state' => 'SUBMITTED',
            'actor_user_id' => (string) Str::uuid(),
            'user_id' => (string) Str::uuid(),
        ];

        foreach ($updateOwnedFields as $field => $value) {
            $response = $this->patchJson(
                "/api/teacher-duty/daily-reports/{$reportId}",
                [
                    'summary' => 'Allowed summary.',
                    $field => $value,
                ]
            );

            $response
                ->assertUnprocessable()
                ->assertJsonValidationErrors([$field]);
        }

        $updateSchoolIdAttempt = $this->patchJson(
            "/api/teacher-duty/daily-reports/{$reportId}",
            [
                'summary' => 'Allowed summary.',
                'school_id' => (string) $this->school->id,
            ]
        );

        $updateSchoolIdAttempt
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['school_id']);

        $submitOwnedFields = [
            'duty_period_id' => (string) Str::uuid(),
            'report_date' => '2026-09-10',
            'status' => 'submitted',
            'summary' => 'Client submission summary.',
            'deadline_at' => '2026-09-09T12:00:00+03:00',
            'created_by' => (string) Str::uuid(),
            'submitted_by' => (string) Str::uuid(),
            'submitted_at' => '2026-09-09T12:00:00+03:00',
            'late' => true,
            'state' => 'SUBMITTED',
            'actor_user_id' => (string) Str::uuid(),
            'user_id' => (string) Str::uuid(),
        ];

        foreach ($submitOwnedFields as $field => $value) {
            $response = $this->postJson(
                "/api/teacher-duty/daily-reports/{$reportId}/submit",
                [
                    $field => $value,
                ]
            );

            $response
                ->assertUnprocessable()
                ->assertJsonValidationErrors([$field]);
        }

        $submitSchoolIdAttempt = $this->postJson(
            "/api/teacher-duty/daily-reports/{$reportId}/submit",
            [
                'school_id' => (string) $this->school->id,
            ]
        );

        $submitSchoolIdAttempt
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['school_id']);

        $this->assertDatabaseHas(
            'teacher_duty_daily_reports',
            [
                'id' => $reportId,
                'school_id' => $this->school->id,
                'duty_period_id' => $period->id,
                'report_date' => '2026-09-09',
                'status' => 'draft',
                'summary' => null,
                'created_by' => $this->reporter->id,
                'submitted_by' => null,
                'submitted_at' => null,
            ]
        );

        $this->assertSame(
            1,
            DB::table('teacher_duty_daily_report_history')
                ->where('daily_report_id', $reportId)
                ->where('event', 'created')
                ->count()
        );

        $this->assertSame(
            0,
            DB::table('teacher_duty_daily_report_history')
                ->where('daily_report_id', $reportId)
                ->where('event', 'submitted')
                ->count()
        );
    }

    public function test_update_summary_supports_null_and_blank_normalization_and_rejects_empty_patch(): void
    {
        $period = $this->createDutyPeriod($this->reporter);
        $this->assignReporter($period, $this->reporter);

        $this->actingAsJwt($this->reporter);

        $open = $this->postJson(
            "/api/teacher-duty/periods/{$period->id}/daily-reports/open",
            [
                'report_date' => '2026-09-09',
            ]
        );

        $open->assertCreated();

        $reportId = (string) $open->json('data.id');

        $textUpdate = $this->patchJson(
            "/api/teacher-duty/daily-reports/{$reportId}",
            [
                'summary' => '  Duty completed normally.  ',
            ]
        );

        $textUpdate
            ->assertOk()
            ->assertJsonPath(
                'data.summary',
                'Duty completed normally.'
            );

        $nullUpdate = $this->patchJson(
            "/api/teacher-duty/daily-reports/{$reportId}",
            [
                'summary' => null,
            ]
        );

        $nullUpdate
            ->assertOk()
            ->assertJsonPath('data.summary', null);

        $blankUpdate = $this->patchJson(
            "/api/teacher-duty/daily-reports/{$reportId}",
            [
                'summary' => "   \t   ",
            ]
        );

        $blankUpdate
            ->assertOk()
            ->assertJsonPath('data.summary', null);

        $emptyPatch = $this->patchJson(
            "/api/teacher-duty/daily-reports/{$reportId}",
            []
        );

        $emptyPatch
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['summary']);

        $this->assertDatabaseHas(
            'teacher_duty_daily_reports',
            [
                'id' => $reportId,
                'status' => 'draft',
                'summary' => null,
                'submitted_by' => null,
                'submitted_at' => null,
            ]
        );

        $this->assertSame(
            1,
            DB::table('teacher_duty_daily_report_history')
                ->where('daily_report_id', $reportId)
                ->where('event', 'created')
                ->count()
        );

        $this->assertSame(
            0,
            DB::table('teacher_duty_daily_report_history')
                ->where('daily_report_id', $reportId)
                ->where('event', 'submitted')
                ->count()
        );
    }

    public function test_submitted_report_is_terminal_and_open_returns_it_unchanged_without_duplicate_evidence(): void
    {
        $period = $this->createDutyPeriod($this->reporter);
        $this->assignReporter($period, $this->reporter);

        $this->actingAsJwt($this->reporter);

        $openUrl =
            "/api/teacher-duty/periods/{$period->id}/daily-reports/open";

        $open = $this->postJson(
            $openUrl,
            [
                'report_date' => '2026-09-09',
            ]
        );

        $open->assertCreated();

        $reportId = (string) $open->json('data.id');

        $update = $this->patchJson(
            "/api/teacher-duty/daily-reports/{$reportId}",
            [
                'summary' => 'Final daily summary.',
            ]
        );

        $update->assertOk();

        $submit = $this->postJson(
            "/api/teacher-duty/daily-reports/{$reportId}/submit",
            []
        );

        $submit
            ->assertOk()
            ->assertJsonPath('data.status', 'submitted');

        $submittedAt = $submit->json('data.submitted_at');

        $createdHistoryBefore = DB::table(
            'teacher_duty_daily_report_history'
        )
            ->where('daily_report_id', $reportId)
            ->where('event', 'created')
            ->count();

        $submittedHistoryBefore = DB::table(
            'teacher_duty_daily_report_history'
        )
            ->where('daily_report_id', $reportId)
            ->where('event', 'submitted')
            ->count();

        $createAuditBefore = DB::table('audit_logs')
            ->where('school_id', $this->school->id)
            ->where('module', 'Teacher Duty Daily Reports')
            ->where('action', 'Create')
            ->where('table_name', 'teacher_duty_daily_reports')
            ->where('record_id', $reportId)
            ->count();

        $reopen = $this->postJson(
            $openUrl,
            [
                'report_date' => '2026-09-09',
            ]
        );

        $reopen
            ->assertOk()
            ->assertJsonPath('data.id', $reportId)
            ->assertJsonPath('data.status', 'submitted')
            ->assertJsonPath(
                'data.summary',
                'Final daily summary.'
            )
            ->assertJsonPath(
                'data.submitted_at',
                $submittedAt
            );

        $updateAfterSubmit = $this->patchJson(
            "/api/teacher-duty/daily-reports/{$reportId}",
            [
                'summary' => 'Attempted amendment.',
            ]
        );

        $updateAfterSubmit
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['report_id']);

        $resubmit = $this->postJson(
            "/api/teacher-duty/daily-reports/{$reportId}/submit",
            []
        );

        $resubmit
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['report_id']);

        $this->assertDatabaseHas(
            'teacher_duty_daily_reports',
            [
                'id' => $reportId,
                'status' => 'submitted',
                'summary' => 'Final daily summary.',
                'submitted_by' => $this->reporter->id,
            ]
        );

        $this->assertSame(
            $createdHistoryBefore,
            DB::table('teacher_duty_daily_report_history')
                ->where('daily_report_id', $reportId)
                ->where('event', 'created')
                ->count()
        );

        $this->assertSame(
            $submittedHistoryBefore,
            DB::table('teacher_duty_daily_report_history')
                ->where('daily_report_id', $reportId)
                ->where('event', 'submitted')
                ->count()
        );

        $this->assertSame(
            $createAuditBefore,
            DB::table('audit_logs')
                ->where('school_id', $this->school->id)
                ->where('module', 'Teacher Duty Daily Reports')
                ->where('action', 'Create')
                ->where('table_name', 'teacher_duty_daily_reports')
                ->where('record_id', $reportId)
                ->count()
        );
    }

    public function test_submission_exactly_at_deadline_is_submitted_and_not_late(): void
    {
        $period = $this->createDutyPeriod($this->reporter);
        $this->assignReporter($period, $this->reporter);

        $this->actingAsJwt($this->reporter);

        $open = $this->postJson(
            "/api/teacher-duty/periods/{$period->id}/daily-reports/open",
            [
                'report_date' => '2026-09-09',
            ]
        );

        $open->assertCreated();

        $reportId = (string) $open->json('data.id');

        CarbonImmutable::setTestNow(
            CarbonImmutable::parse(
                '2026-09-09 19:00:00',
                'Africa/Nairobi'
            )
        );

        $this->actingAsJwt($this->reporter);

        try {
            $submit = $this->postJson(
                "/api/teacher-duty/daily-reports/{$reportId}/submit",
                []
            );

            $submit
                ->assertOk()
                ->assertJsonPath('data.status', 'submitted');

            $state = $this->getJson(
                "/api/teacher-duty/periods/{$period->id}/daily-reports/state".
                '?report_date=2026-09-09'
            );

            $state
                ->assertOk()
                ->assertJsonPath('data.state', 'SUBMITTED')
                ->assertJsonPath('data.late', false);

            $this->assertNotNull(
                $state->json('data.submitted_at')
            );

            $this->assertSame(
                $state->json('data.deadline_at'),
                $state->json('data.submitted_at')
            );
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_overdue_draft_remains_editable_and_can_be_submitted_late_over_http(): void
    {
        $period = $this->createDutyPeriod($this->reporter);
        $this->assignReporter($period, $this->reporter);

        $this->actingAsJwt($this->reporter);

        $open = $this->postJson(
            "/api/teacher-duty/periods/{$period->id}/daily-reports/open",
            [
                'report_date' => '2026-09-09',
            ]
        );

        $open->assertCreated();

        $reportId = (string) $open->json('data.id');

        CarbonImmutable::setTestNow(
            CarbonImmutable::parse(
                '2026-09-09 19:00:01',
                'Africa/Nairobi'
            )
        );

        $this->actingAsJwt($this->reporter);

        try {
            $beforeUpdate = $this->getJson(
                "/api/teacher-duty/periods/{$period->id}/daily-reports/state".
                '?report_date=2026-09-09'
            );

            $beforeUpdate
                ->assertOk()
                ->assertJsonPath('data.state', 'OVERDUE')
                ->assertJsonPath('data.late', false);

            $update = $this->patchJson(
                "/api/teacher-duty/daily-reports/{$reportId}",
                [
                    'summary' => 'Late report completed.',
                ]
            );

            $update
                ->assertOk()
                ->assertJsonPath('data.status', 'draft')
                ->assertJsonPath(
                    'data.summary',
                    'Late report completed.'
                );

            $submit = $this->postJson(
                "/api/teacher-duty/daily-reports/{$reportId}/submit",
                []
            );

            $submit
                ->assertOk()
                ->assertJsonPath('data.status', 'submitted');

            $afterSubmit = $this->getJson(
                "/api/teacher-duty/periods/{$period->id}/daily-reports/state".
                '?report_date=2026-09-09'
            );

            $afterSubmit
                ->assertOk()
                ->assertJsonPath('data.state', 'SUBMITTED')
                ->assertJsonPath('data.late', true);

            $this->assertSame(
                2,
                DB::table('teacher_duty_daily_report_history')
                    ->where('daily_report_id', $reportId)
                    ->count()
            );
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_report_can_be_submitted_with_zero_occurrences_and_null_summary_over_http(): void
    {
        $period = $this->createDutyPeriod($this->reporter);
        $this->assignReporter($period, $this->reporter);

        $this->actingAsJwt($this->reporter);

        $open = $this->postJson(
            "/api/teacher-duty/periods/{$period->id}/daily-reports/open",
            [
                'report_date' => '2026-09-09',
            ]
        );

        $open
            ->assertCreated()
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.summary', null);

        $reportId = (string) $open->json('data.id');

        $this->assertSame(
            0,
            DB::table('teacher_duty_occurrences')
                ->where('school_id', $this->school->id)
                ->where('duty_period_id', $period->id)
                ->whereDate('occurrence_date', '2026-09-09')
                ->count()
        );

        $submit = $this->postJson(
            "/api/teacher-duty/daily-reports/{$reportId}/submit",
            []
        );

        $submit
            ->assertOk()
            ->assertJsonPath('data.status', 'submitted')
            ->assertJsonPath('data.summary', null)
            ->assertJsonPath(
                'data.submitted_by',
                (string) $this->reporter->id
            );

        $this->assertNotNull(
            $submit->json('data.submitted_at')
        );

        $this->assertDatabaseHas(
            'teacher_duty_daily_reports',
            [
                'id' => $reportId,
                'status' => 'submitted',
                'summary' => null,
                'submitted_by' => $this->reporter->id,
            ]
        );

        $this->assertSame(
            2,
            DB::table('teacher_duty_daily_report_history')
                ->where('daily_report_id', $reportId)
                ->count()
        );

        $this->assertSame(
            0,
            DB::table('teacher_duty_occurrences')
                ->where('school_id', $this->school->id)
                ->where('duty_period_id', $period->id)
                ->whereDate('occurrence_date', '2026-09-09')
                ->count()
        );
    }

    public function test_responsible_teacher_without_submit_permission_is_forbidden_from_all_daily_operations(): void
    {
        $period = $this->createDutyPeriod($this->reporter);
        $this->assignReporter($period, $this->reporter);

        $open = app(TeacherDutyDailyReportService::class)
            ->openReport(
                (string) $this->school->id,
                (string) $period->id,
                '2026-09-09',
                (string) $this->reporter->id
            );

        $user = $this->createUser($this->school);
        TeacherBuilder::create(
            $this->school,
            $user
        );

        $this->assignReporter(
            $period,
            $user
        );

        $this->actingAsJwt($user);

        $attempts = [
            [
                'POST',
                "/api/teacher-duty/periods/{$period->id}/daily-reports/open",
                [
                    'report_date' => '2026-09-10',
                ],
            ],
            [
                'GET',
                "/api/teacher-duty/periods/{$period->id}/daily-reports/state".
                '?report_date=2026-09-09',
                [],
            ],
            [
                'PATCH',
                "/api/teacher-duty/daily-reports/{$open->id}",
                [
                    'summary' => 'Must not be accepted.',
                ],
            ],
            [
                'POST',
                "/api/teacher-duty/daily-reports/{$open->id}/submit",
                [],
            ],
        ];

        foreach ($attempts as [$method, $url, $payload]) {
            $response = match ($method) {
                'GET' => $this->getJson($url),
                'POST' => $this->postJson($url, $payload),
                'PATCH' => $this->patchJson($url, $payload),
            };

            $response->assertForbidden();
        }

        $this->assertDatabaseMissing(
            'teacher_duty_daily_reports',
            [
                'school_id' => $this->school->id,
                'duty_period_id' => $period->id,
                'report_date' => '2026-09-10',
            ]
        );

        $this->assertDatabaseHas(
            'teacher_duty_daily_reports',
            [
                'id' => $open->id,
                'status' => 'draft',
                'summary' => null,
                'submitted_by' => null,
                'submitted_at' => null,
            ]
        );
    }

    public function test_daily_state_remains_readable_but_mutations_are_blocked_when_school_is_non_operational(): void
    {
        $period = $this->createDutyPeriod($this->reporter);
        $this->assignReporter($period, $this->reporter);

        $this->actingAsJwt($this->reporter);

        $open = $this->postJson(
            "/api/teacher-duty/periods/{$period->id}/daily-reports/open",
            [
                'report_date' => '2026-09-09',
            ]
        );

        $open->assertCreated();

        $reportId = (string) $open->json('data.id');

        $this->makeSchoolNonOperational($this->school);

        $state = $this->getJson(
            "/api/teacher-duty/periods/{$period->id}/daily-reports/state".
            '?report_date=2026-09-09'
        );

        $state
            ->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'state',
                    'deadline_at',
                    'submitted_at',
                    'late',
                ],
            ]);

        $blockedOpen = $this->postJson(
            "/api/teacher-duty/periods/{$period->id}/daily-reports/open",
            [
                'report_date' => '2026-09-10',
            ]
        );

        $this->assertFalse(
            $blockedOpen->isSuccessful(),
            'Daily report opening must be blocked for a non-operational school.'
        );

        $blockedUpdate = $this->patchJson(
            "/api/teacher-duty/daily-reports/{$reportId}",
            [
                'summary' => 'Must remain unchanged.',
            ]
        );

        $this->assertFalse(
            $blockedUpdate->isSuccessful(),
            'Daily report updating must be blocked for a non-operational school.'
        );

        $blockedSubmit = $this->postJson(
            "/api/teacher-duty/daily-reports/{$reportId}/submit",
            []
        );

        $this->assertFalse(
            $blockedSubmit->isSuccessful(),
            'Daily report submission must be blocked for a non-operational school.'
        );

        $this->assertDatabaseMissing(
            'teacher_duty_daily_reports',
            [
                'school_id' => $this->school->id,
                'duty_period_id' => $period->id,
                'report_date' => '2026-09-10',
            ]
        );

        $this->assertDatabaseHas(
            'teacher_duty_daily_reports',
            [
                'id' => $reportId,
                'status' => 'draft',
                'summary' => null,
                'submitted_by' => null,
                'submitted_at' => null,
            ]
        );
    }

    public function test_daily_responses_expose_only_the_frozen_allowlists(): void
    {
        $period = $this->createDutyPeriod($this->reporter);
        $this->assignReporter($period, $this->reporter);

        $this->actingAsJwt($this->reporter);

        $openResponse = $this->postJson(
            "/api/teacher-duty/periods/{$period->id}/daily-reports/open",
            [
                'report_date' => '2026-09-09',
            ]
        )->assertCreated();

        $reportId = (string) $openResponse->json('data.id');

        $entityKeys = [
            'id',
            'duty_period_id',
            'report_date',
            'status',
            'summary',
            'deadline_at',
            'created_by',
            'submitted_by',
            'submitted_at',
            'created_at',
            'updated_at',
        ];

        $this->assertSame(
            ['message', 'data'],
            array_keys($openResponse->json())
        );

        $this->assertSame(
            $entityKeys,
            array_keys($openResponse->json('data'))
        );

        $this->assertArrayNotHasKey(
            'school_id',
            $openResponse->json('data')
        );

        $updateResponse = $this->patchJson(
            "/api/teacher-duty/daily-reports/{$reportId}",
            [
                'summary' => '  Frozen response allowlist evidence.  ',
            ]
        )->assertOk();

        $this->assertSame(
            ['message', 'data'],
            array_keys($updateResponse->json())
        );

        $this->assertSame(
            $entityKeys,
            array_keys($updateResponse->json('data'))
        );

        $this->assertArrayNotHasKey(
            'school_id',
            $updateResponse->json('data')
        );

        $stateResponse = $this->getJson(
            "/api/teacher-duty/periods/{$period->id}/daily-reports/state"
            .'?report_date=2026-09-09'
        )->assertOk();

        $this->assertSame(
            ['data'],
            array_keys($stateResponse->json())
        );

        $this->assertSame(
            [
                'state',
                'deadline_at',
                'submitted_at',
                'late',
            ],
            array_keys($stateResponse->json('data'))
        );

        $this->assertArrayNotHasKey(
            'school_id',
            $stateResponse->json('data')
        );

        $submitResponse = $this->postJson(
            "/api/teacher-duty/daily-reports/{$reportId}/submit",
            []
        )->assertOk();

        $this->assertSame(
            ['message', 'data'],
            array_keys($submitResponse->json())
        );

        $this->assertSame(
            $entityKeys,
            array_keys($submitResponse->json('data'))
        );

        $this->assertArrayNotHasKey(
            'school_id',
            $submitResponse->json('data')
        );
    }

    public function test_daily_mutations_write_expected_audit_actor_actions_and_safe_values(): void
    {
        $period = $this->createDutyPeriod($this->reporter);
        $this->assignReporter($period, $this->reporter);

        $this->actingAsJwt($this->reporter);

        $openResponse = $this->postJson(
            "/api/teacher-duty/periods/{$period->id}/daily-reports/open",
            [
                'report_date' => '2026-09-09',
            ]
        )->assertCreated();

        $reportId = (string) $openResponse->json('data.id');

        $this->patchJson(
            "/api/teacher-duty/daily-reports/{$reportId}",
            [
                'summary' => 'Audit summary evidence.',
            ]
        )->assertOk();

        $this->postJson(
            "/api/teacher-duty/daily-reports/{$reportId}/submit",
            []
        )->assertOk();

        $audits = DB::table('audit_logs')
            ->where('school_id', $this->school->id)
            ->where('user_id', $this->reporter->id)
            ->where('module', 'Teacher Duty Daily Reports')
            ->where('table_name', 'teacher_duty_daily_reports')
            ->where('record_id', $reportId)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        $this->assertCount(3, $audits);

        $this->assertSame(
            [
                'Create' => 1,
                'Update' => 2,
            ],
            $audits
                ->countBy('action')
                ->sortKeys()
                ->all()
        );

        $createAudit = $audits->first(
            fn ($audit): bool => $audit->action === 'Create'
        );

        $updateAudit = $audits->first(
            function ($audit): bool {
                if ($audit->action !== 'Update') {
                    return false;
                }

                $oldValues = json_decode(
                    (string) $audit->old_values,
                    true
                );

                return array_key_exists(
                    'summary',
                    $oldValues ?? []
                );
            }
        );

        $submitAudit = $audits->first(
            function ($audit): bool {
                if ($audit->action !== 'Update') {
                    return false;
                }

                $oldValues = json_decode(
                    (string) $audit->old_values,
                    true
                );

                return array_key_exists(
                    'status',
                    $oldValues ?? []
                );
            }
        );

        $this->assertNotNull($createAudit);
        $this->assertNotNull($updateAudit);
        $this->assertNotNull($submitAudit);

        $createOldValues = $createAudit->old_values === null
            ? null
            : json_decode(
                (string) $createAudit->old_values,
                true
            );

        $createNewValues = json_decode(
            (string) $createAudit->new_values,
            true
        );

        $updateOldValues = json_decode(
            (string) $updateAudit->old_values,
            true
        );

        $updateNewValues = json_decode(
            (string) $updateAudit->new_values,
            true
        );

        $submitOldValues = json_decode(
            (string) $submitAudit->old_values,
            true
        );

        $submitNewValues = json_decode(
            (string) $submitAudit->new_values,
            true
        );

        $this->assertNull($createOldValues);

        $expectedCreateAuditKeys = [
            'duty_period_id',
            'report_date',
            'status',
            'summary',
            'deadline_at',
        ];

        $actualCreateAuditKeys = array_keys(
            $createNewValues
        );

        sort($expectedCreateAuditKeys);
        sort($actualCreateAuditKeys);

        $this->assertSame(
            $expectedCreateAuditKeys,
            $actualCreateAuditKeys
        );

        $this->assertSame(
            [
                'summary' => null,
            ],
            $updateOldValues
        );

        $this->assertSame(
            [
                'summary' => 'Audit summary evidence.',
            ],
            $updateNewValues
        );

        $this->assertSame(
            'draft',
            $submitOldValues['status']
        );

        $this->assertNull(
            $submitOldValues['submitted_at']
        );

        $this->assertSame(
            'submitted',
            $submitNewValues['status']
        );

        $this->assertNotNull(
            $submitNewValues['submitted_at']
        );

        foreach ($audits as $audit) {
            $this->assertSame(
                $this->reporter->id,
                $audit->user_id
            );

            foreach ([
                'id',
                'school_id',
                'created_by',
                'submitted_by',
                'created_at',
                'updated_at',
            ] as $authorityField) {
                $oldValues = $audit->old_values === null
                    ? []
                    : json_decode(
                        (string) $audit->old_values,
                        true
                    );

                $newValues = $audit->new_values === null
                    ? []
                    : json_decode(
                        (string) $audit->new_values,
                        true
                    );

                $this->assertArrayNotHasKey(
                    $authorityField,
                    $oldValues
                );

                $this->assertArrayNotHasKey(
                    $authorityField,
                    $newValues
                );
            }
        }
    }

    private function actingAsJwt(User $user): void
    {
        $token = JWTAuth::fromUser($user);

        $this->withHeaders([
            'Authorization' => "Bearer {$token}",
        ]);
    }

    private function createSettings(
        School $school,
        string $deadline = '17:00:00',
        int $graceMinutes = 120
    ): void {
        DB::table('school_settings')->insert([
            'school_id' => $school->id,
            'teacher_duty_report_deadline_time' => $deadline,
            'teacher_duty_report_grace_minutes' => $graceMinutes,
        ]);
    }

    private function createDutyPeriod(
        User $actor
    ): TeacherDutyPeriod {
        return app(TeacherDutyRosterService::class)
            ->createPeriod(
                (string) $actor->school_id,
                '2026-09-07',
                '2026-09-11',
                null,
                (string) $actor->id
            );
    }

    private function assignReporter(
        TeacherDutyPeriod $period,
        User $user
    ): TeacherDutyAssignment {
        $teacher = $this->teacherForUser($user);

        return app(TeacherDutyRosterService::class)
            ->assignTeacher(
                (string) $user->school_id,
                (string) $period->id,
                (string) $teacher->id,
                (string) $user->id
            );
    }

    private function teacherForUser(User $user): Teacher
    {
        return Teacher::query()
            ->withoutGlobalScopes()
            ->where(
                'school_id',
                $user->school_id
            )
            ->where(
                'user_id',
                $user->id
            )
            ->where(
                'active',
                true
            )
            ->where(
                'is_deleted',
                false
            )
            ->firstOrFail();
    }

    private function createSchool(): School
    {
        return School::query()->create([
            'id' => (string) Str::uuid(),
            'school_name' => 'Teacher Duty Daily HTTP '.
                Str::upper(Str::random(8)),
            'school_code' => 'TDD-'.
                Str::upper(Str::random(8)),
            'short_name' => 'TDD',
            'registration_number' => 'REG-'.
                Str::upper(Str::random(10)),
            'school_type' => 'Primary',
            'county' => 'Nairobi',
            'phone' => '+2547'.
                random_int(10000000, 99999999),
            'email' => Str::lower(Str::random(10)).
                '@example.test',
            'timezone' => 'Africa/Nairobi',
            'locale' => 'en',
            'active' => true,
        ]);
    }

    private function createUser(
        School $school
    ): User {
        $role = Role::query()->create([
            'id' => (string) Str::uuid(),
            'role_name' => 'Teacher Duty Daily HTTP '.
                Str::upper(Str::random(8)),
            'description' => 'Teacher duty daily HTTP test role',
            'active' => true,
        ]);

        return User::query()->create([
            'id' => (string) Str::uuid(),
            'school_id' => $school->id,
            'role_id' => $role->id,
            'first_name' => 'Teacher',
            'last_name' => 'Duty Daily',
            'username' => 'teacher_duty_daily_'.
                Str::lower(Str::random(10)),
            'email' => Str::lower(Str::random(10)).
                '@example.test',
            'password_hash' => bcrypt('Password123!'),
            'active' => true,
            'first_login' => false,
        ]);
    }

    private function completeOperationalSetup(
        School $school
    ): void {
        $academicYearId = (string) Str::uuid();
        $gradeId = (string) Str::uuid();

        DB::table('academic_years')->insert([
            'id' => $academicYearId,
            'school_id' => $school->id,
            'year_name' => 'Operational '.
                Str::upper(Str::random(8)),
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'active' => true,
            'created_at' => now(),
        ]);

        DB::table('terms')->insert([
            'id' => (string) Str::uuid(),
            'school_id' => $school->id,
            'academic_year_id' => $academicYearId,
            'term_name' => 'Operational '.
                Str::upper(Str::random(6)),
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'active' => true,
            'created_at' => now(),
        ]);

        DB::table('grades')->insert([
            'id' => $gradeId,
            'school_id' => $school->id,
            'grade_name' => 'Readiness '.
                Str::upper(Str::random(8)),
            'grade_order' => random_int(
                3001,
                4000
            ),
            'active' => true,
            'created_at' => now(),
        ]);

        DB::table('streams')->insert([
            'id' => (string) Str::uuid(),
            'school_id' => $school->id,
            'grade_id' => $gradeId,
            'stream_name' => 'Readiness '.
                Str::upper(Str::random(8)),
            'active' => true,
            'created_at' => now(),
        ]);
    }

    private function makeSchoolNonOperational(
        School $school
    ): void {
        DB::table('streams')
            ->where('school_id', $school->id)
            ->delete();

        DB::table('terms')
            ->where('school_id', $school->id)
            ->update([
                'active' => false,
            ]);
    }

    private function grantPermission(
        User $user,
        string $permissionName
    ): void {
        $permissionId = DB::table('permissions')
            ->where(
                'permission_name',
                $permissionName
            )
            ->value('id');

        if (! $permissionId) {
            throw new \RuntimeException(
                'Required migrated permission '.
                "[{$permissionName}] was not found."
            );
        }

        if (! $user->role_id) {
            throw new \RuntimeException(
                'Teacher Duty daily HTTP test actor '.
                'has no primary role.'
            );
        }

        DB::table('role_permissions')
            ->insertOrIgnore([
                'id' => (string) Str::uuid(),
                'role_id' => $user->role_id,
                'permission_id' => $permissionId,
                'created_at' => now(),
            ]);
    }
}
