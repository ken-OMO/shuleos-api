<?php

declare(strict_types=1);

namespace Tests\Feature\TeacherDuty;

use App\Models\Role;
use App\Models\School;
use App\Models\Teacher;
use App\Models\TeacherDutyAssignment;
use App\Models\TeacherDutyPeriod;
use App\Models\User;
use App\Services\TeacherDuty\TeacherDutyRosterService;
use App\Services\TeacherDuty\TeacherDutyWeeklyReportService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Tests\Support\Database\TeacherBuilder;
use Tests\TestCase;

final class TeacherDutyWeeklyReportManagementTest extends TestCase
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
                'api/teacher-duty/periods/{period}/weekly-reports/open',
                'permission:submit_teacher_duty_reports',
                true,
            ],
            [
                'GET',
                'api/teacher-duty/weekly-reports/{report}/state',
                null,
                false,
            ],
            [
                'PATCH',
                'api/teacher-duty/weekly-reports/{report}',
                'permission:submit_teacher_duty_reports',
                true,
            ],
            [
                'POST',
                'api/teacher-duty/weekly-reports/{report}/submit',
                'permission:submit_teacher_duty_reports',
                true,
            ],
            [
                'POST',
                'api/teacher-duty/weekly-reports/{report}/resubmit',
                'permission:submit_teacher_duty_reports',
                true,
            ],
            [
                'POST',
                'api/teacher-duty/weekly-reports/{report}/review',
                'permission:review_teacher_duty_reports',
                true,
            ],
        ];

        foreach (
            $contracts as [$method, $uri, $permission, $operational]
        ) {
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
            $excludedMiddleware = $route->excludedMiddleware();

            $this->assertContains(
                'module.permission',
                $excludedMiddleware,
                "{$method} {$uri} must exclude module.permission."
            );

            if ($permission !== null) {
                $this->assertContains(
                    $permission,
                    $middleware,
                    "{$method} {$uri} must require {$permission}."
                );
            } else {
                $this->assertNotContains(
                    'permission:submit_teacher_duty_reports',
                    $middleware,
                    "{$method} {$uri} must not require reporter permission."
                );

                $this->assertNotContains(
                    'permission:review_teacher_duty_reports',
                    $middleware,
                    "{$method} {$uri} must not require reviewer permission."
                );
            }

            if ($operational) {
                $this->assertContains(
                    'school.operational',
                    $middleware,
                    "{$method} {$uri} must require school.operational."
                );
            } else {
                $this->assertNotContains(
                    'school.operational',
                    $middleware,
                    "{$method} {$uri} must remain readable when non-operational."
                );
            }
        }
    }

    public function test_reporter_can_open_weekly_report_with_frozen_response_and_create_evidence(): void
    {
        $period = $this->createDutyPeriod($this->reporter);
        $this->assignReporter($period, $this->reporter);

        $this->actingAsJwt($this->reporter);

        $response = $this->postJson(
            "/api/teacher-duty/periods/{$period->id}/weekly-reports/open",
            []
        );

        $response
            ->assertCreated()
            ->assertJsonPath(
                'message',
                'Teacher duty weekly report opened successfully.'
            )
            ->assertJsonPath(
                'data.duty_period_id',
                (string) $period->id
            )
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.summary', null)
            ->assertJsonPath('data.highlights', null)
            ->assertJsonPath('data.challenges', null)
            ->assertJsonPath('data.recommendations', null)
            ->assertJsonPath(
                'data.created_by',
                (string) $this->reporter->id
            );

        $this->assertSame(
            [
                'message',
                'data',
            ],
            array_keys($response->json())
        );

        $this->assertSame(
            [
                'id',
                'duty_period_id',
                'status',
                'summary',
                'highlights',
                'challenges',
                'recommendations',
                'evidence_snapshot',
                'created_by',
                'submitted_by',
                'submitted_at',
                'reviewed_by',
                'reviewed_at',
                'review_comment',
                'created_at',
                'updated_at',
            ],
            array_keys($response->json('data'))
        );

        $this->assertArrayNotHasKey(
            'school_id',
            $response->json('data')
        );

        $reportId = (string) $response->json('data.id');

        $this->assertDatabaseHas(
            'teacher_duty_weekly_reports',
            [
                'id' => $reportId,
                'school_id' => $this->school->id,
                'duty_period_id' => $period->id,
                'status' => 'draft',
                'created_by' => $this->reporter->id,
            ]
        );

        $this->assertDatabaseHas(
            'teacher_duty_weekly_report_history',
            [
                'weekly_report_id' => $reportId,
                'actor_user_id' => $this->reporter->id,
                'from_status' => null,
                'to_status' => 'draft',
                'event' => 'created',
            ]
        );

        $this->assertSame(
            1,
            DB::table('audit_logs')
                ->where('school_id', $this->school->id)
                ->where('user_id', $this->reporter->id)
                ->where(
                    'module',
                    'Teacher Duty Weekly Reports'
                )
                ->where('action', 'Create')
                ->where(
                    'table_name',
                    'teacher_duty_weekly_reports'
                )
                ->where('record_id', $reportId)
                ->count()
        );
    }

    public function test_open_is_idempotent_without_duplicate_weekly_report_history_or_create_audit(): void
    {
        $period = $this->createDutyPeriod($this->reporter);
        $this->assignReporter($period, $this->reporter);

        $this->actingAsJwt($this->reporter);

        $url =
            "/api/teacher-duty/periods/{$period->id}/weekly-reports/open";

        $first = $this->postJson($url, []);

        $first
            ->assertCreated()
            ->assertJsonPath('data.status', 'draft');

        $reportId = (string) $first->json('data.id');

        $second = $this->postJson($url, []);

        $second
            ->assertOk()
            ->assertJsonPath('data.id', $reportId)
            ->assertJsonPath('data.status', 'draft');

        $this->assertSame(
            [
                'data',
            ],
            array_keys($second->json())
        );

        $this->assertSame(
            [
                'id',
                'duty_period_id',
                'status',
                'summary',
                'highlights',
                'challenges',
                'recommendations',
                'evidence_snapshot',
                'created_by',
                'submitted_by',
                'submitted_at',
                'reviewed_by',
                'reviewed_at',
                'review_comment',
                'created_at',
                'updated_at',
            ],
            array_keys($second->json('data'))
        );

        $this->assertArrayNotHasKey(
            'school_id',
            $second->json('data')
        );

        $this->assertSame(
            1,
            DB::table('teacher_duty_weekly_reports')
                ->where('school_id', $this->school->id)
                ->where('duty_period_id', $period->id)
                ->count()
        );

        $this->assertSame(
            1,
            DB::table('teacher_duty_weekly_report_history')
                ->where('weekly_report_id', $reportId)
                ->where('event', 'created')
                ->count()
        );

        $this->assertSame(
            1,
            DB::table('audit_logs')
                ->where('school_id', $this->school->id)
                ->where('user_id', $this->reporter->id)
                ->where(
                    'module',
                    'Teacher Duty Weekly Reports'
                )
                ->where('action', 'Create')
                ->where(
                    'table_name',
                    'teacher_duty_weekly_reports'
                )
                ->where('record_id', $reportId)
                ->count()
        );
    }

    public function test_reporter_can_update_all_four_draft_narratives_with_exact_response_and_audit(): void
    {
        $period = $this->createDutyPeriod($this->reporter);
        $this->assignReporter($period, $this->reporter);

        $report = app(
            TeacherDutyWeeklyReportService::class
        )->openReport(
            (string) $this->school->id,
            (string) $period->id,
            (string) $this->reporter->id
        );

        $this->actingAsJwt($this->reporter);

        $response = $this->patchJson(
            "/api/teacher-duty/weekly-reports/{$report->id}",
            [
                'summary' => '  Weekly duty summary.  ',
                'highlights' => '  Learners were punctual.  ',
                'challenges' => '  Two late arrivals.  ',
                'recommendations' => '  Reinforce morning checks.  ',
            ]
        );

        $response
            ->assertOk()
            ->assertJsonPath(
                'message',
                'Teacher duty weekly report updated successfully.'
            )
            ->assertJsonPath(
                'data.summary',
                'Weekly duty summary.'
            )
            ->assertJsonPath(
                'data.highlights',
                'Learners were punctual.'
            )
            ->assertJsonPath(
                'data.challenges',
                'Two late arrivals.'
            )
            ->assertJsonPath(
                'data.recommendations',
                'Reinforce morning checks.'
            )
            ->assertJsonPath('data.status', 'draft');

        $this->assertSame(
            [
                'message',
                'data',
            ],
            array_keys($response->json())
        );

        $this->assertSame(
            [
                'id',
                'duty_period_id',
                'status',
                'summary',
                'highlights',
                'challenges',
                'recommendations',
                'evidence_snapshot',
                'created_by',
                'submitted_by',
                'submitted_at',
                'reviewed_by',
                'reviewed_at',
                'review_comment',
                'created_at',
                'updated_at',
            ],
            array_keys($response->json('data'))
        );

        $this->assertArrayNotHasKey(
            'school_id',
            $response->json('data')
        );

        $this->assertDatabaseHas(
            'teacher_duty_weekly_reports',
            [
                'id' => $report->id,
                'school_id' => $this->school->id,
                'duty_period_id' => $period->id,
                'status' => 'draft',
                'summary' => 'Weekly duty summary.',
                'highlights' => 'Learners were punctual.',
                'challenges' => 'Two late arrivals.',
                'recommendations' => 'Reinforce morning checks.',
                'created_by' => $this->reporter->id,
                'submitted_by' => null,
                'submitted_at' => null,
            ]
        );

        $audit = DB::table('audit_logs')
            ->where('school_id', $this->school->id)
            ->where('user_id', $this->reporter->id)
            ->where(
                'module',
                'Teacher Duty Weekly Reports'
            )
            ->where('action', 'Update')
            ->where(
                'table_name',
                'teacher_duty_weekly_reports'
            )
            ->where('record_id', $report->id)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();

        $this->assertNotNull($audit);

        $oldValues = json_decode(
            (string) $audit->old_values,
            true
        );

        $newValues = json_decode(
            (string) $audit->new_values,
            true
        );

        $this->assertEqualsCanonicalizing(
            [
                'summary' => null,
                'highlights' => null,
                'challenges' => null,
                'recommendations' => null,
            ],
            $oldValues
        );

        $this->assertEqualsCanonicalizing(
            [
                'summary' => 'Weekly duty summary.',
                'highlights' => 'Learners were punctual.',
                'challenges' => 'Two late arrivals.',
                'recommendations' => 'Reinforce morning checks.',
            ],
            $newValues
        );

        $expectedNarrativeKeys = [
            'summary',
            'highlights',
            'challenges',
            'recommendations',
        ];

        $oldKeys = array_keys($oldValues);
        $newKeys = array_keys($newValues);

        sort($expectedNarrativeKeys);
        sort($oldKeys);
        sort($newKeys);

        $this->assertSame(
            $expectedNarrativeKeys,
            $oldKeys
        );

        $this->assertSame(
            $expectedNarrativeKeys,
            $newKeys
        );

        foreach ([
            'id',
            'school_id',
            'duty_period_id',
            'status',
            'evidence_snapshot',
            'created_by',
            'submitted_by',
            'submitted_at',
            'reviewed_by',
            'reviewed_at',
            'review_comment',
            'created_at',
            'updated_at',
        ] as $authorityField) {
            $this->assertArrayNotHasKey(
                $authorityField,
                $oldValues
            );

            $this->assertArrayNotHasKey(
                $authorityField,
                $newValues
            );
        }

        $this->assertSame(
            1,
            DB::table('teacher_duty_weekly_report_history')
                ->where('weekly_report_id', $report->id)
                ->where('event', 'created')
                ->count()
        );
    }

    public function test_update_requires_all_four_client_owned_narrative_fields(): void
    {
        $period = $this->createDutyPeriod($this->reporter);
        $this->assignReporter($period, $this->reporter);

        $report = app(
            TeacherDutyWeeklyReportService::class
        )->openReport(
            (string) $this->school->id,
            (string) $period->id,
            (string) $this->reporter->id
        );

        $this->actingAsJwt($this->reporter);

        $response = $this->patchJson(
            "/api/teacher-duty/weekly-reports/{$report->id}",
            [
                'summary' => 'Only one field supplied.',
            ]
        );

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'highlights',
                'challenges',
                'recommendations',
            ]);

        $this->assertDatabaseHas(
            'teacher_duty_weekly_reports',
            [
                'id' => $report->id,
                'status' => 'draft',
                'summary' => null,
                'highlights' => null,
                'challenges' => null,
                'recommendations' => null,
            ]
        );

        $this->assertSame(
            0,
            DB::table('audit_logs')
                ->where('school_id', $this->school->id)
                ->where('module', 'Teacher Duty Weekly Reports')
                ->where('action', 'Update')
                ->where('record_id', $report->id)
                ->count()
        );
    }

    public function test_update_rejects_client_control_of_server_owned_weekly_report_fields(): void
    {
        $period = $this->createDutyPeriod($this->reporter);
        $this->assignReporter($period, $this->reporter);

        $report = app(
            TeacherDutyWeeklyReportService::class
        )->openReport(
            (string) $this->school->id,
            (string) $period->id,
            (string) $this->reporter->id
        );

        $this->actingAsJwt($this->reporter);

        $serverOwnedFields = [
            'school_id' => (string) $this->school->id,
            'duty_period_id' => (string) $period->id,
            'status' => 'approved',
            'evidence_snapshot' => ['client' => 'controlled'],
            'created_by' => (string) $this->reporter->id,
            'submitted_by' => (string) $this->reporter->id,
            'submitted_at' => '2026-09-20T12:00:00Z',
            'reviewed_by' => (string) $this->reporter->id,
            'reviewed_at' => '2026-09-20T12:00:00Z',
            'review_comment' => 'Client controlled.',
            'actor_user_id' => (string) $this->reporter->id,
            'user_id' => (string) $this->reporter->id,
        ];

        foreach ($serverOwnedFields as $field => $value) {
            $payload = [
                'summary' => null,
                'highlights' => null,
                'challenges' => null,
                'recommendations' => null,
                $field => $value,
            ];

            $response = $this->patchJson(
                "/api/teacher-duty/weekly-reports/{$report->id}",
                $payload
            );

            $response
                ->assertUnprocessable()
                ->assertJsonValidationErrors([$field]);
        }

        $this->assertDatabaseHas(
            'teacher_duty_weekly_reports',
            [
                'id' => $report->id,
                'status' => 'draft',
                'summary' => null,
                'highlights' => null,
                'challenges' => null,
                'recommendations' => null,
                'submitted_by' => null,
                'submitted_at' => null,
                'reviewed_by' => null,
                'reviewed_at' => null,
                'review_comment' => null,
            ]
        );

        $this->assertSame(
            0,
            DB::table('audit_logs')
                ->where('school_id', $this->school->id)
                ->where('module', 'Teacher Duty Weekly Reports')
                ->where('action', 'Update')
                ->where('record_id', $report->id)
                ->count()
        );
    }

    public function test_state_rejects_client_control_of_weekly_report_fields(): void
    {
        $period = $this->createDutyPeriod($this->reporter);
        $this->assignReporter($period, $this->reporter);

        $report = app(
            TeacherDutyWeeklyReportService::class
        )->openReport(
            (string) $this->school->id,
            (string) $period->id,
            (string) $this->reporter->id
        );

        $this->actingAsJwt($this->reporter);

        $clientControlledFields = [
            'school_id' => (string) $this->school->id,
            'duty_period_id' => (string) $period->id,
            'status' => 'approved',
            'summary' => 'Client controlled.',
            'highlights' => 'Client controlled.',
            'challenges' => 'Client controlled.',
            'recommendations' => 'Client controlled.',
            'evidence_snapshot' => 'client-controlled',
            'created_by' => (string) $this->reporter->id,
            'submitted_by' => (string) $this->reporter->id,
            'submitted_at' => '2026-09-20T12:00:00Z',
            'reviewed_by' => (string) $this->reporter->id,
            'reviewed_at' => '2026-09-20T12:00:00Z',
            'review_comment' => 'Client controlled.',
            'actor_user_id' => (string) $this->reporter->id,
            'user_id' => (string) $this->reporter->id,
        ];

        foreach ($clientControlledFields as $field => $value) {
            $response = $this->getJson(
                "/api/teacher-duty/weekly-reports/{$report->id}/state?".
                http_build_query([$field => $value])
            );

            $response
                ->assertUnprocessable()
                ->assertJsonValidationErrors([$field]);
        }

        $this->assertDatabaseHas(
            'teacher_duty_weekly_reports',
            [
                'id' => $report->id,
                'status' => 'draft',
                'summary' => null,
                'highlights' => null,
                'challenges' => null,
                'recommendations' => null,
                'submitted_by' => null,
                'reviewed_by' => null,
            ]
        );

        $this->assertSame(
            0,
            DB::table('audit_logs')
                ->where('school_id', $this->school->id)
                ->where('module', 'Teacher Duty Weekly Reports')
                ->where('record_id', $report->id)
                ->count()
        );
    }

    public function test_draft_cannot_be_submitted_before_period_is_authoritatively_ended(): void
    {
        $period = $this->createDutyPeriod($this->reporter);
        $this->assignReporter($period, $this->reporter);

        $report = app(
            TeacherDutyWeeklyReportService::class
        )->openReport(
            (string) $this->school->id,
            (string) $period->id,
            (string) $this->reporter->id
        );

        $this->actingAsJwt($this->reporter);

        $response = $this->postJson(
            "/api/teacher-duty/weekly-reports/{$report->id}/submit",
            []
        );

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['period_id']);

        $report->refresh();

        $this->assertSame('draft', $report->status);
        $this->assertNull($report->submitted_by);
        $this->assertNull($report->submitted_at);
        $this->assertNull($report->evidence_snapshot);

        $this->assertSame(
            0,
            DB::table('teacher_duty_weekly_report_history')
                ->where('weekly_report_id', $report->id)
                ->where('event', 'submitted')
                ->count()
        );

        $this->assertSame(
            0,
            DB::table('audit_logs')
                ->where('school_id', $this->school->id)
                ->where('module', 'Teacher Duty Weekly Reports')
                ->where('action', 'Update')
                ->where('record_id', $report->id)
                ->count()
        );
    }

    public function test_reporter_can_submit_draft_after_authoritative_period_end_with_evidence_history_and_audit(): void
    {
        $period = $this->createDutyPeriod($this->reporter);
        $this->assignReporter($period, $this->reporter);

        $report = app(
            TeacherDutyWeeklyReportService::class
        )->openReport(
            (string) $this->school->id,
            (string) $period->id,
            (string) $this->reporter->id
        );

        DB::table('teacher_duty_periods')
            ->where('id', $period->id)
            ->update([
                'active' => false,
                'ended_by' => $this->reporter->id,
                'ended_at' => now(),
                'end_reason' => 'Duty period completed.',
                'updated_at' => now(),
            ]);

        $this->actingAsJwt($this->reporter);

        $response = $this->postJson(
            "/api/teacher-duty/weekly-reports/{$report->id}/submit",
            []
        );

        $response
            ->assertOk()
            ->assertJsonPath(
                'message',
                'Teacher duty weekly report submitted successfully.'
            )
            ->assertJsonPath('data.status', 'submitted')
            ->assertJsonPath(
                'data.submitted_by',
                (string) $this->reporter->id
            )
            ->assertJsonPath('data.reviewed_by', null)
            ->assertJsonPath('data.reviewed_at', null)
            ->assertJsonPath('data.review_comment', null);

        $this->assertSame(
            [
                'message',
                'data',
            ],
            array_keys($response->json())
        );

        $this->assertSame(
            [
                'id',
                'duty_period_id',
                'status',
                'summary',
                'highlights',
                'challenges',
                'recommendations',
                'evidence_snapshot',
                'created_by',
                'submitted_by',
                'submitted_at',
                'reviewed_by',
                'reviewed_at',
                'review_comment',
                'created_at',
                'updated_at',
            ],
            array_keys($response->json('data'))
        );

        $this->assertArrayNotHasKey(
            'school_id',
            $response->json('data')
        );

        $this->assertNotNull(
            $response->json('data.submitted_at')
        );

        $snapshot = $response->json(
            'data.evidence_snapshot'
        );

        $this->assertIsArray($snapshot);
        $this->assertSame(
            1,
            $snapshot['snapshot_version']
        );
        $this->assertSame(
            5,
            $snapshot['expected_daily_report_count']
        );
        $this->assertSame(
            5,
            $snapshot['not_started_daily_report_count']
                + $snapshot['draft_daily_report_count']
                + $snapshot['overdue_daily_report_count']
                + $snapshot['submitted_daily_report_count']
        );
        $this->assertSame(
            0,
            $snapshot['total_occurrence_count']
        );
        $this->assertSame(
            [],
            $snapshot['occurrence_category_breakdown']
        );
        $this->assertNotEmpty(
            $snapshot['snapshot_generated_at']
        );

        $report->refresh();

        $this->assertSame('submitted', $report->status);
        $this->assertSame(
            (string) $this->reporter->id,
            (string) $report->submitted_by
        );
        $this->assertNotNull($report->submitted_at);
        $this->assertIsArray($report->evidence_snapshot);
        $this->assertSame(
            $snapshot,
            $report->evidence_snapshot
        );

        $history = DB::table(
            'teacher_duty_weekly_report_history'
        )
            ->where('school_id', $this->school->id)
            ->where('weekly_report_id', $report->id)
            ->where('event', 'submitted')
            ->first();

        $this->assertNotNull($history);
        $this->assertSame(
            'draft',
            $history->from_status
        );
        $this->assertSame(
            'submitted',
            $history->to_status
        );
        $this->assertSame(
            (string) $this->reporter->id,
            (string) $history->actor_user_id
        );

        $historySnapshot = json_decode(
            (string) $history->evidence_snapshot,
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        $this->assertSame(
            $snapshot,
            $historySnapshot
        );

        $audit = DB::table('audit_logs')
            ->where('school_id', $this->school->id)
            ->where('user_id', $this->reporter->id)
            ->where(
                'module',
                'Teacher Duty Weekly Reports'
            )
            ->where('action', 'Update')
            ->where(
                'table_name',
                'teacher_duty_weekly_reports'
            )
            ->where('record_id', $report->id)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();

        $this->assertNotNull($audit);

        $oldValues = json_decode(
            (string) $audit->old_values,
            true
        );

        $newValues = json_decode(
            (string) $audit->new_values,
            true
        );

        $this->assertSame(
            'draft',
            $oldValues['status']
        );
        $this->assertNull(
            $oldValues['submitted_by']
        );
        $this->assertNull(
            $oldValues['submitted_at']
        );
        $this->assertNull(
            $oldValues['reviewed_by']
        );
        $this->assertNull(
            $oldValues['reviewed_at']
        );
        $this->assertNull(
            $oldValues['review_comment']
        );

        $this->assertSame(
            'submitted',
            $newValues['status']
        );
        $this->assertSame(
            (string) $this->reporter->id,
            (string) $newValues['submitted_by']
        );
        $this->assertNotEmpty(
            $newValues['submitted_at']
        );
        $this->assertNull(
            $newValues['reviewed_by']
        );
        $this->assertNull(
            $newValues['reviewed_at']
        );
        $this->assertNull(
            $newValues['review_comment']
        );

        $expectedLifecycleKeys = [
            'status',
            'submitted_by',
            'submitted_at',
            'reviewed_by',
            'reviewed_at',
            'review_comment',
        ];

        $oldKeys = array_keys($oldValues);
        $newKeys = array_keys($newValues);

        sort($expectedLifecycleKeys);
        sort($oldKeys);
        sort($newKeys);

        $this->assertSame(
            $expectedLifecycleKeys,
            $oldKeys
        );

        $this->assertSame(
            $expectedLifecycleKeys,
            $newKeys
        );

        $this->assertSame(
            1,
            DB::table('audit_logs')
                ->where('school_id', $this->school->id)
                ->where('user_id', $this->reporter->id)
                ->where(
                    'module',
                    'Teacher Duty Weekly Reports'
                )
                ->where('action', 'Update')
                ->where(
                    'table_name',
                    'teacher_duty_weekly_reports'
                )
                ->where('record_id', $report->id)
                ->count()
        );
    }

    public function test_changes_requested_can_be_edited_and_resubmitted_with_preserved_history_and_fresh_evidence(): void
    {
        $period = $this->createDutyPeriod($this->reporter);
        $this->assignReporter($period, $this->reporter);

        $reviewer = $this->createUser($this->school);

        $this->grantPermission(
            $reviewer,
            'review_teacher_duty_reports'
        );

        $report = app(
            TeacherDutyWeeklyReportService::class
        )->openReport(
            (string) $this->school->id,
            (string) $period->id,
            (string) $this->reporter->id
        );

        DB::table('teacher_duty_periods')
            ->where('id', $period->id)
            ->update([
                'active' => false,
                'ended_by' => $this->reporter->id,
                'ended_at' => now(),
                'end_reason' => 'Duty period completed.',
                'updated_at' => now(),
            ]);

        $submitted = app(
            TeacherDutyWeeklyReportService::class
        )->submitReport(
            (string) $this->school->id,
            (string) $report->id,
            (string) $this->reporter->id
        );

        $firstSnapshot = $submitted->evidence_snapshot;

        $this->actingAsJwt($reviewer);

        $reviewResponse = $this->postJson(
            "/api/teacher-duty/weekly-reports/{$report->id}/review",
            [
                'decision' => 'changes_requested',
                'comment' => 'Please revise the weekly narrative.',
            ]
        );

        $reviewResponse
            ->assertOk()
            ->assertJsonPath(
                'message',
                'Teacher duty weekly report reviewed successfully.'
            )
            ->assertJsonPath(
                'data.status',
                'changes_requested'
            )
            ->assertJsonPath(
                'data.reviewed_by',
                (string) $reviewer->id
            )
            ->assertJsonPath(
                'data.review_comment',
                'Please revise the weekly narrative.'
            );

        $this->assertNotNull(
            $reviewResponse->json('data.reviewed_at')
        );

        $this->assertSame(
            $firstSnapshot,
            $reviewResponse->json('data.evidence_snapshot')
        );

        $changesHistory = DB::table(
            'teacher_duty_weekly_report_history'
        )
            ->where('school_id', $this->school->id)
            ->where('weekly_report_id', $report->id)
            ->where('event', 'changes_requested')
            ->first();

        $this->assertNotNull($changesHistory);
        $this->assertSame(
            'submitted',
            $changesHistory->from_status
        );
        $this->assertSame(
            'changes_requested',
            $changesHistory->to_status
        );
        $this->assertSame(
            (string) $reviewer->id,
            (string) $changesHistory->actor_user_id
        );
        $this->assertSame(
            'Please revise the weekly narrative.',
            $changesHistory->comment
        );

        $this->assertSame(
            $firstSnapshot,
            json_decode(
                (string) $changesHistory->evidence_snapshot,
                true,
                512,
                JSON_THROW_ON_ERROR
            )
        );

        $reviewAudit = DB::table('audit_logs')
            ->where('school_id', $this->school->id)
            ->where('user_id', $reviewer->id)
            ->where(
                'module',
                'Teacher Duty Weekly Reports'
            )
            ->where('action', 'Update')
            ->where('record_id', $report->id)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();

        $this->assertNotNull($reviewAudit);

        $reviewOldValues = json_decode(
            (string) $reviewAudit->old_values,
            true
        );

        $reviewNewValues = json_decode(
            (string) $reviewAudit->new_values,
            true
        );

        $this->assertSame(
            'submitted',
            $reviewOldValues['status']
        );
        $this->assertSame(
            'changes_requested',
            $reviewNewValues['status']
        );
        $this->assertSame(
            (string) $reviewer->id,
            (string) $reviewNewValues['reviewed_by']
        );
        $this->assertSame(
            'Please revise the weekly narrative.',
            $reviewNewValues['review_comment']
        );

        $this->actingAsJwt($this->reporter);

        $updateResponse = $this->patchJson(
            "/api/teacher-duty/weekly-reports/{$report->id}",
            [
                'summary' => 'Revised summary.',
                'highlights' => 'Revised highlights.',
                'challenges' => 'Revised challenges.',
                'recommendations' => 'Revised recommendations.',
            ]
        );

        $updateResponse
            ->assertOk()
            ->assertJsonPath(
                'data.status',
                'changes_requested'
            )
            ->assertJsonPath(
                'data.summary',
                'Revised summary.'
            )
            ->assertJsonPath(
                'data.highlights',
                'Revised highlights.'
            )
            ->assertJsonPath(
                'data.challenges',
                'Revised challenges.'
            )
            ->assertJsonPath(
                'data.recommendations',
                'Revised recommendations.'
            );

        $resubmitResponse = $this->postJson(
            "/api/teacher-duty/weekly-reports/{$report->id}/resubmit",
            []
        );

        $resubmitResponse
            ->assertOk()
            ->assertJsonPath(
                'message',
                'Teacher duty weekly report resubmitted successfully.'
            )
            ->assertJsonPath(
                'data.status',
                'submitted'
            )
            ->assertJsonPath(
                'data.submitted_by',
                (string) $this->reporter->id
            )
            ->assertJsonPath('data.reviewed_by', null)
            ->assertJsonPath('data.reviewed_at', null)
            ->assertJsonPath('data.review_comment', null);

        $this->assertNotNull(
            $resubmitResponse->json('data.submitted_at')
        );

        $secondSnapshot = $resubmitResponse->json(
            'data.evidence_snapshot'
        );

        $this->assertIsArray($secondSnapshot);
        $this->assertNotEmpty(
            $secondSnapshot['snapshot_generated_at']
        );

        $resubmitHistory = DB::table(
            'teacher_duty_weekly_report_history'
        )
            ->where('school_id', $this->school->id)
            ->where('weekly_report_id', $report->id)
            ->where('event', 'resubmitted')
            ->first();

        $this->assertNotNull($resubmitHistory);
        $this->assertSame(
            'changes_requested',
            $resubmitHistory->from_status
        );
        $this->assertSame(
            'submitted',
            $resubmitHistory->to_status
        );
        $this->assertSame(
            (string) $this->reporter->id,
            (string) $resubmitHistory->actor_user_id
        );

        $this->assertSame(
            $secondSnapshot,
            json_decode(
                (string) $resubmitHistory->evidence_snapshot,
                true,
                512,
                JSON_THROW_ON_ERROR
            )
        );

        $changesHistoryAfterResubmit = DB::table(
            'teacher_duty_weekly_report_history'
        )
            ->where('id', $changesHistory->id)
            ->first();

        $this->assertSame(
            $firstSnapshot,
            json_decode(
                (string) $changesHistoryAfterResubmit->evidence_snapshot,
                true,
                512,
                JSON_THROW_ON_ERROR
            )
        );

        $resubmitAudit = DB::table('audit_logs')
            ->where('school_id', $this->school->id)
            ->where('user_id', $this->reporter->id)
            ->where(
                'module',
                'Teacher Duty Weekly Reports'
            )
            ->where('action', 'Update')
            ->where('record_id', $report->id)
            ->where(
                'description',
                'Resubmitted Teacher duty weekly report.'
            )
            ->first();

        $this->assertNotNull($resubmitAudit);

        $resubmitOldValues = json_decode(
            (string) $resubmitAudit->old_values,
            true
        );

        $resubmitNewValues = json_decode(
            (string) $resubmitAudit->new_values,
            true
        );

        $this->assertSame(
            'changes_requested',
            $resubmitOldValues['status']
        );
        $this->assertSame(
            'submitted',
            $resubmitNewValues['status']
        );
        $this->assertSame(
            (string) $this->reporter->id,
            (string) $resubmitNewValues['submitted_by']
        );
        $this->assertNull(
            $resubmitNewValues['reviewed_by']
        );
        $this->assertNull(
            $resubmitNewValues['reviewed_at']
        );
        $this->assertNull(
            $resubmitNewValues['review_comment']
        );

        $this->assertSame(
            1,
            DB::table('teacher_duty_weekly_report_history')
                ->where('weekly_report_id', $report->id)
                ->where('event', 'changes_requested')
                ->count()
        );

        $this->assertSame(
            1,
            DB::table('teacher_duty_weekly_report_history')
                ->where('weekly_report_id', $report->id)
                ->where('event', 'resubmitted')
                ->count()
        );
    }

    public function test_review_rejects_invalid_decision_and_requires_meaningful_comment(): void
    {
        $period = $this->createDutyPeriod($this->reporter);
        $this->assignReporter($period, $this->reporter);

        $reviewer = $this->createUser($this->school);

        $this->grantPermission(
            $reviewer,
            'review_teacher_duty_reports'
        );

        $report = app(
            TeacherDutyWeeklyReportService::class
        )->openReport(
            (string) $this->school->id,
            (string) $period->id,
            (string) $this->reporter->id
        );

        DB::table('teacher_duty_periods')
            ->where('id', $period->id)
            ->update([
                'active' => false,
                'ended_by' => $this->reporter->id,
                'ended_at' => now(),
                'end_reason' => 'Duty period completed.',
                'updated_at' => now(),
            ]);

        app(
            TeacherDutyWeeklyReportService::class
        )->submitReport(
            (string) $this->school->id,
            (string) $report->id,
            (string) $this->reporter->id
        );

        $this->actingAsJwt($reviewer);

        $this->postJson(
            "/api/teacher-duty/weekly-reports/{$report->id}/review",
            [
                'decision' => 'returned',
                'comment' => 'Invalid decision.',
            ]
        )
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['decision']);

        $this->postJson(
            "/api/teacher-duty/weekly-reports/{$report->id}/review",
            [
                'decision' => 'changes_requested',
                'comment' => '   ',
            ]
        )
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['comment']);

        $this->postJson(
            "/api/teacher-duty/weekly-reports/{$report->id}/review",
            [
                'decision' => 'rejected',
                'comment' => '   ',
            ]
        )
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['comment']);

        $fresh = $report->fresh();

        $this->assertSame('submitted', $fresh->status);
        $this->assertNull($fresh->reviewed_by);
        $this->assertNull($fresh->reviewed_at);
        $this->assertNull($fresh->review_comment);

        $this->assertSame(
            0,
            DB::table('teacher_duty_weekly_report_history')
                ->where('weekly_report_id', $report->id)
                ->whereIn('event', [
                    'changes_requested',
                    'approved',
                    'rejected',
                ])
                ->count()
        );
    }

    public function test_submitter_cannot_review_own_submitted_weekly_report(): void
    {
        $period = $this->createDutyPeriod($this->reporter);
        $this->assignReporter($period, $this->reporter);

        $this->grantPermission(
            $this->reporter,
            'review_teacher_duty_reports'
        );

        $report = app(
            TeacherDutyWeeklyReportService::class
        )->openReport(
            (string) $this->school->id,
            (string) $period->id,
            (string) $this->reporter->id
        );

        DB::table('teacher_duty_periods')
            ->where('id', $period->id)
            ->update([
                'active' => false,
                'ended_by' => $this->reporter->id,
                'ended_at' => now(),
                'end_reason' => 'Duty period completed.',
                'updated_at' => now(),
            ]);

        app(
            TeacherDutyWeeklyReportService::class
        )->submitReport(
            (string) $this->school->id,
            (string) $report->id,
            (string) $this->reporter->id
        );

        $this->actingAsJwt($this->reporter);

        $response = $this->postJson(
            "/api/teacher-duty/weekly-reports/{$report->id}/review",
            [
                'decision' => 'approved',
                'comment' => null,
            ]
        );

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['reviewer']);

        $fresh = $report->fresh();

        $this->assertSame('submitted', $fresh->status);
        $this->assertNull($fresh->reviewed_by);
        $this->assertNull($fresh->reviewed_at);
        $this->assertNull($fresh->review_comment);

        $this->assertSame(
            0,
            DB::table('teacher_duty_weekly_report_history')
                ->where('weekly_report_id', $report->id)
                ->where('event', 'approved')
                ->count()
        );
    }

    public function test_reviewer_can_approve_and_approved_report_is_terminal(): void
    {
        $period = $this->createDutyPeriod($this->reporter);
        $this->assignReporter($period, $this->reporter);

        $reviewer = $this->createUser($this->school);
        $secondReviewer = $this->createUser($this->school);

        $this->grantPermission(
            $reviewer,
            'review_teacher_duty_reports'
        );

        $this->grantPermission(
            $secondReviewer,
            'review_teacher_duty_reports'
        );

        $report = app(
            TeacherDutyWeeklyReportService::class
        )->openReport(
            (string) $this->school->id,
            (string) $period->id,
            (string) $this->reporter->id
        );

        DB::table('teacher_duty_periods')
            ->where('id', $period->id)
            ->update([
                'active' => false,
                'ended_by' => $this->reporter->id,
                'ended_at' => now(),
                'end_reason' => 'Duty period completed.',
                'updated_at' => now(),
            ]);

        $submitted = app(
            TeacherDutyWeeklyReportService::class
        )->submitReport(
            (string) $this->school->id,
            (string) $report->id,
            (string) $this->reporter->id
        );

        $snapshot = $submitted->evidence_snapshot;

        $this->actingAsJwt($reviewer);

        $response = $this->postJson(
            "/api/teacher-duty/weekly-reports/{$report->id}/review",
            [
                'decision' => 'approved',
                'comment' => null,
            ]
        );

        $response
            ->assertOk()
            ->assertJsonPath(
                'message',
                'Teacher duty weekly report reviewed successfully.'
            )
            ->assertJsonPath('data.status', 'approved')
            ->assertJsonPath(
                'data.reviewed_by',
                (string) $reviewer->id
            )
            ->assertJsonPath('data.review_comment', null);

        $this->assertNotNull(
            $response->json('data.reviewed_at')
        );

        $this->assertSame(
            $snapshot,
            $response->json('data.evidence_snapshot')
        );

        $history = DB::table(
            'teacher_duty_weekly_report_history'
        )
            ->where('weekly_report_id', $report->id)
            ->where('event', 'approved')
            ->first();

        $this->assertNotNull($history);
        $this->assertSame('submitted', $history->from_status);
        $this->assertSame('approved', $history->to_status);
        $this->assertSame(
            (string) $reviewer->id,
            (string) $history->actor_user_id
        );
        $this->assertNull($history->comment);

        $this->assertSame(
            $snapshot,
            json_decode(
                (string) $history->evidence_snapshot,
                true,
                512,
                JSON_THROW_ON_ERROR
            )
        );

        $this->actingAsJwt($secondReviewer);

        $this->postJson(
            "/api/teacher-duty/weekly-reports/{$report->id}/review",
            [
                'decision' => 'rejected',
                'comment' => 'Second review must not be allowed.',
            ]
        )
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['report_id']);

        $this->assertSame(
            'approved',
            $report->fresh()->status
        );

        $this->assertSame(
            1,
            DB::table('teacher_duty_weekly_report_history')
                ->where('weekly_report_id', $report->id)
                ->where('event', 'approved')
                ->count()
        );
    }

    public function test_reviewer_can_reject_and_rejected_report_cannot_be_resubmitted(): void
    {
        $period = $this->createDutyPeriod($this->reporter);
        $this->assignReporter($period, $this->reporter);

        $reviewer = $this->createUser($this->school);

        $this->grantPermission(
            $reviewer,
            'review_teacher_duty_reports'
        );

        $report = app(
            TeacherDutyWeeklyReportService::class
        )->openReport(
            (string) $this->school->id,
            (string) $period->id,
            (string) $this->reporter->id
        );

        DB::table('teacher_duty_periods')
            ->where('id', $period->id)
            ->update([
                'active' => false,
                'ended_by' => $this->reporter->id,
                'ended_at' => now(),
                'end_reason' => 'Duty period completed.',
                'updated_at' => now(),
            ]);

        $submitted = app(
            TeacherDutyWeeklyReportService::class
        )->submitReport(
            (string) $this->school->id,
            (string) $report->id,
            (string) $this->reporter->id
        );

        $snapshot = $submitted->evidence_snapshot;

        $this->actingAsJwt($reviewer);

        $response = $this->postJson(
            "/api/teacher-duty/weekly-reports/{$report->id}/review",
            [
                'decision' => 'rejected',
                'comment' => 'The weekly report requires substantial correction.',
            ]
        );

        $response
            ->assertOk()
            ->assertJsonPath(
                'message',
                'Teacher duty weekly report reviewed successfully.'
            )
            ->assertJsonPath('data.status', 'rejected')
            ->assertJsonPath(
                'data.reviewed_by',
                (string) $reviewer->id
            )
            ->assertJsonPath(
                'data.review_comment',
                'The weekly report requires substantial correction.'
            );

        $this->assertNotNull(
            $response->json('data.reviewed_at')
        );

        $this->assertSame(
            $snapshot,
            $response->json('data.evidence_snapshot')
        );

        $history = DB::table(
            'teacher_duty_weekly_report_history'
        )
            ->where('weekly_report_id', $report->id)
            ->where('event', 'rejected')
            ->first();

        $this->assertNotNull($history);
        $this->assertSame('submitted', $history->from_status);
        $this->assertSame('rejected', $history->to_status);
        $this->assertSame(
            (string) $reviewer->id,
            (string) $history->actor_user_id
        );
        $this->assertSame(
            'The weekly report requires substantial correction.',
            $history->comment
        );

        $this->assertSame(
            $snapshot,
            json_decode(
                (string) $history->evidence_snapshot,
                true,
                512,
                JSON_THROW_ON_ERROR
            )
        );

        $this->actingAsJwt($this->reporter);

        $this->postJson(
            "/api/teacher-duty/weekly-reports/{$report->id}/resubmit",
            []
        )
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['report_id']);

        $this->assertSame(
            'rejected',
            $report->fresh()->status
        );

        $this->assertSame(
            1,
            DB::table('teacher_duty_weekly_report_history')
                ->where('weekly_report_id', $report->id)
                ->where('event', 'rejected')
                ->count()
        );
    }

    public function test_ended_assignment_still_authorizes_weekly_reporter_operations(): void
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
            "/api/teacher-duty/periods/{$period->id}/weekly-reports/open",
            []
        );

        $open
            ->assertCreated()
            ->assertJsonPath('data.status', 'draft');

        $reportId = (string) $open->json('data.id');

        $this->patchJson(
            "/api/teacher-duty/weekly-reports/{$reportId}",
            [
                'summary' => 'Historical responsibility summary.',
                'highlights' => 'Historical responsibility highlights.',
                'challenges' => null,
                'recommendations' => 'Historical responsibility recommendations.',
            ]
        )
            ->assertOk()
            ->assertJsonPath(
                'data.summary',
                'Historical responsibility summary.'
            );

        $this->getJson(
            "/api/teacher-duty/weekly-reports/{$reportId}/state"
        )
            ->assertOk()
            ->assertJsonPath('data.state', 'DRAFT');
    }

    public function test_responsible_reporter_without_submit_permission_is_forbidden_from_weekly_mutations_and_state(): void
    {
        $period = $this->createDutyPeriod($this->reporter);
        $this->assignReporter($period, $this->reporter);

        $report = app(
            TeacherDutyWeeklyReportService::class
        )->openReport(
            (string) $this->school->id,
            (string) $period->id,
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

        $this->postJson(
            "/api/teacher-duty/periods/{$period->id}/weekly-reports/open",
            []
        )->assertForbidden();

        $this->patchJson(
            "/api/teacher-duty/weekly-reports/{$report->id}",
            [
                'summary' => 'Must not be accepted.',
                'highlights' => null,
                'challenges' => null,
                'recommendations' => null,
            ]
        )->assertForbidden();

        $this->postJson(
            "/api/teacher-duty/weekly-reports/{$report->id}/submit",
            []
        )->assertForbidden();

        $this->postJson(
            "/api/teacher-duty/weekly-reports/{$report->id}/resubmit",
            []
        )->assertForbidden();

        $this->getJson(
            "/api/teacher-duty/weekly-reports/{$report->id}/state"
        )
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'actor_user_id',
            ]);

        $fresh = $report->fresh();

        $this->assertSame('draft', $fresh->status);
        $this->assertNull($fresh->summary);
        $this->assertNull($fresh->submitted_by);
        $this->assertNull($fresh->submitted_at);
    }

    public function test_malformed_weekly_route_identifiers_fail_as_generic_422_without_database_leak(): void
    {
        $this->actingAsJwt($this->reporter);

        $attempts = [
            [
                'POST',
                '/api/teacher-duty/periods/not-a-uuid/weekly-reports/open',
                [],
            ],
            [
                'GET',
                '/api/teacher-duty/weekly-reports/not-a-uuid/state',
                null,
            ],
            [
                'PATCH',
                '/api/teacher-duty/weekly-reports/not-a-uuid',
                [
                    'summary' => 'Malformed resource probe.',
                    'highlights' => null,
                    'challenges' => null,
                    'recommendations' => null,
                ],
            ],
            [
                'POST',
                '/api/teacher-duty/weekly-reports/not-a-uuid/submit',
                [],
            ],
            [
                'POST',
                '/api/teacher-duty/weekly-reports/not-a-uuid/resubmit',
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

    public function test_cross_tenant_weekly_period_and_report_resources_fail_closed(): void
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
            "/api/teacher-duty/periods/{$foreignPeriod->id}/weekly-reports/open",
            []
        );

        $foreignOpen->assertCreated();

        $foreignReportId = (string) $foreignOpen->json(
            'data.id'
        );

        $this->actingAsJwt($this->reporter);

        $attempts = [
            [
                'POST',
                "/api/teacher-duty/periods/{$foreignPeriod->id}/weekly-reports/open",
                [],
            ],
            [
                'GET',
                "/api/teacher-duty/weekly-reports/{$foreignReportId}/state",
                null,
            ],
            [
                'PATCH',
                "/api/teacher-duty/weekly-reports/{$foreignReportId}",
                [
                    'summary' => 'Cross-tenant mutation probe.',
                    'highlights' => null,
                    'challenges' => null,
                    'recommendations' => null,
                ],
            ],
            [
                'POST',
                "/api/teacher-duty/weekly-reports/{$foreignReportId}/submit",
                [],
            ],
            [
                'POST',
                "/api/teacher-duty/weekly-reports/{$foreignReportId}/resubmit",
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
            'teacher_duty_weekly_reports',
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
    }

    public function test_weekly_state_remains_readable_but_mutations_are_blocked_when_school_is_non_operational(): void
    {
        $period = $this->createDutyPeriod($this->reporter);
        $this->assignReporter($period, $this->reporter);

        $this->actingAsJwt($this->reporter);

        $open = $this->postJson(
            "/api/teacher-duty/periods/{$period->id}/weekly-reports/open",
            []
        );

        $open->assertCreated();

        $reportId = (string) $open->json('data.id');

        $this->makeSchoolNonOperational(
            $this->school
        );

        $this->getJson(
            "/api/teacher-duty/weekly-reports/{$reportId}/state"
        )
            ->assertOk()
            ->assertJsonPath('data.state', 'DRAFT');

        $blockedOpen = $this->postJson(
            "/api/teacher-duty/periods/{$period->id}/weekly-reports/open",
            []
        );

        $this->assertFalse(
            $blockedOpen->isSuccessful(),
            'Weekly report opening must be blocked for a non-operational school.'
        );

        $blockedUpdate = $this->patchJson(
            "/api/teacher-duty/weekly-reports/{$reportId}",
            [
                'summary' => 'Must remain unchanged.',
                'highlights' => null,
                'challenges' => null,
                'recommendations' => null,
            ]
        );

        $this->assertFalse(
            $blockedUpdate->isSuccessful(),
            'Weekly report updating must be blocked for a non-operational school.'
        );

        $blockedSubmit = $this->postJson(
            "/api/teacher-duty/weekly-reports/{$reportId}/submit",
            []
        );

        $this->assertFalse(
            $blockedSubmit->isSuccessful(),
            'Weekly report submission must be blocked for a non-operational school.'
        );

        $blockedResubmit = $this->postJson(
            "/api/teacher-duty/weekly-reports/{$reportId}/resubmit",
            []
        );

        $this->assertFalse(
            $blockedResubmit->isSuccessful(),
            'Weekly report resubmission must be blocked for a non-operational school.'
        );

        $fresh = DB::table('teacher_duty_weekly_reports')
            ->where('school_id', $this->school->id)
            ->where('id', $reportId)
            ->first();

        $this->assertNotNull($fresh);
        $this->assertSame('draft', $fresh->status);
        $this->assertNull($fresh->summary);
        $this->assertNull($fresh->submitted_by);
        $this->assertNull($fresh->submitted_at);
    }

    public function test_reporter_with_period_responsibility_can_read_weekly_state(): void
    {
        $period = $this->createDutyPeriod($this->reporter);

        $this->assignReporter(
            $period,
            $this->reporter
        );

        $report = app(TeacherDutyWeeklyReportService::class)
            ->openReport(
                (string) $this->school->id,
                (string) $period->id,
                (string) $this->reporter->id
            );

        $this->actingAsJwt($this->reporter);

        $response = $this->getJson(
            "/api/teacher-duty/weekly-reports/{$report->id}/state"
        );

        $response
            ->assertOk()
            ->assertJsonPath('data.state', 'DRAFT');
    }

    public function test_reviewer_without_teacher_duty_assignment_can_read_weekly_state(): void
    {
        $period = $this->createDutyPeriod($this->reporter);

        $this->assignReporter(
            $period,
            $this->reporter
        );

        $report = app(TeacherDutyWeeklyReportService::class)
            ->openReport(
                (string) $this->school->id,
                (string) $period->id,
                (string) $this->reporter->id
            );

        $reviewer = $this->createUser($this->school);

        $this->grantPermission(
            $reviewer,
            'review_teacher_duty_reports'
        );

        $this->actingAsJwt($reviewer);

        $response = $this->getJson(
            "/api/teacher-duty/weekly-reports/{$report->id}/state"
        );

        $response
            ->assertOk()
            ->assertJsonPath('data.state', 'DRAFT');

        $this->assertDatabaseMissing(
            'teachers',
            [
                'school_id' => $this->school->id,
                'user_id' => $reviewer->id,
            ]
        );

    }

    public function test_same_school_actor_with_neither_authority_cannot_read_weekly_state(): void
    {
        $period = $this->createDutyPeriod($this->reporter);

        $this->assignReporter(
            $period,
            $this->reporter
        );

        $report = app(TeacherDutyWeeklyReportService::class)
            ->openReport(
                (string) $this->school->id,
                (string) $period->id,
                (string) $this->reporter->id
            );

        $actor = $this->createUser($this->school);

        $this->actingAsJwt($actor);

        $response = $this->getJson(
            "/api/teacher-duty/weekly-reports/{$report->id}/state"
        );

        $response
            ->assertStatus(422)
            ->assertJsonValidationErrors([
                'actor_user_id',
            ]);
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
            'school_name' => 'Teacher Duty Weekly HTTP '.
                Str::upper(Str::random(8)),
            'school_code' => 'TDW-'.
                Str::upper(Str::random(8)),
            'short_name' => 'TDW',
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
            'role_name' => 'Teacher Duty Weekly HTTP '.
                Str::upper(Str::random(8)),
            'description' => 'Teacher duty weekly HTTP test role',
            'active' => true,
        ]);

        return User::query()->create([
            'id' => (string) Str::uuid(),
            'school_id' => $school->id,
            'role_id' => $role->id,
            'first_name' => 'Teacher',
            'last_name' => 'Duty Weekly',
            'username' => 'teacher_duty_weekly_'.
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
                4001,
                5000
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
                'Teacher Duty weekly HTTP test actor '.
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
