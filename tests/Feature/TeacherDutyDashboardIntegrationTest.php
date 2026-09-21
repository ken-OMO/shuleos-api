<?php

namespace Tests\Feature;

use App\Models\Teacher;
use App\Models\TeacherDutyAssignment;
use App\Models\TeacherDutyPeriod;
use App\Models\User;
use App\Services\TeacherDuty\TeacherDutyDailyReportService;
use App\Services\TeacherDuty\TeacherDutyRosterService;
use App\Services\TeacherDuty\TeacherDutyWeeklyReportService;
use App\Services\TeacherPortal\TeacherPortalMobileService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Tests\Support\Database\AcademicYearBuilder;
use Tests\Support\Database\RoleBuilder;
use Tests\Support\Database\SchoolBuilder;
use Tests\Support\Database\TeacherBuilder;
use Tests\Support\Database\TermBuilder;
use Tests\Support\Database\UserBuilder;
use Tests\TestCase;

class TeacherDutyDashboardIntegrationTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'jwt.secret' => str_repeat('a', 64),
        ]);
    }

    public function test_teacher_dashboard_route_retains_access_teacher_portal_permission(): void
    {
        $route = collect(Route::getRoutes()->getRoutes())
            ->first(
                fn ($route) => $route->uri() === 'api/teacher/dashboard'
                    && in_array('GET', $route->methods(), true)
            );

        $this->assertNotNull($route);

        $this->assertContains(
            'permission:access_teacher_portal',
            $route->gatherMiddleware()
        );
    }

    public function test_authorized_reporter_sees_current_teacher_duty_projection(): void
    {
        CarbonImmutable::setTestNow(
            CarbonImmutable::parse(
                '2026-09-09 10:00:00',
                'Africa/Nairobi'
            )
        );

        try {
            $school = SchoolBuilder::create();

            $role = RoleBuilder::create([
                'role_name' => 'Teacher',
            ]);

            $user = UserBuilder::create(
                $school,
                $role
            );

            TeacherBuilder::create(
                $school,
                $user
            );

            $user = User::with('role')
                ->findOrFail($user->id);

            $this->grantPermission(
                $user,
                'submit_teacher_duty_reports'
            );

            $this->teacherDutySettings(
                $school
            );

            $period = app(TeacherDutyRosterService::class)
                ->createPeriod(
                    (string) $school->id,
                    '2026-09-07',
                    '2026-09-11',
                    null,
                    (string) $user->id
                );

            $this->assignReporter(
                $period,
                $user
            );

            $dashboard = app(TeacherPortalMobileService::class)
                ->dashboard($user);

            $this->assertIsArray(
                $dashboard['teacher_duty']
            );

            $this->assertSame(
                (string) $period->id,
                $dashboard['teacher_duty']['duty_period_id']
            );
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_authorized_reporter_projects_linked_academic_week_id_and_number(): void
    {
        CarbonImmutable::setTestNow(
            CarbonImmutable::parse(
                '2026-09-09 10:00:00',
                'Africa/Nairobi'
            )
        );

        try {
            [$school, $user] = $this->teacherUser();

            $this->grantPermission(
                $user,
                'submit_teacher_duty_reports'
            );

            $this->teacherDutySettings(
                $school
            );

            $academicYear = AcademicYearBuilder::create(
                $school
            );

            $term = TermBuilder::create(
                $school,
                $academicYear,
                [
                    'term_name' => 'Term 3',
                    'start_date' => '2026-09-01',
                    'end_date' => '2026-11-30',
                ]
            );

            $weekId = (string) Str::uuid();

            DB::table('academic_weeks')->insert([
                'id' => $weekId,
                'school_id' => $school->id,
                'academic_year_id' => $academicYear->id,
                'term_id' => $term->id,
                'week_number' => 4,
                'start_date' => '2026-09-07',
                'end_date' => '2026-09-11',
                'active' => true,
            ]);

            $period = app(TeacherDutyRosterService::class)
                ->createPeriod(
                    (string) $school->id,
                    '2026-09-07',
                    '2026-09-11',
                    $weekId,
                    (string) $user->id
                );

            $this->assignReporter(
                $period,
                $user
            );

            $dashboard = app(TeacherPortalMobileService::class)
                ->dashboard($user);

            $this->assertSame(
                (string) $period->id,
                $dashboard['teacher_duty']['duty_period_id']
            );

            $this->assertSame(
                $weekId,
                $dashboard['teacher_duty']['academic_week_id']
            );

            $this->assertSame(
                4,
                $dashboard['teacher_duty']['week_number']
            );
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_teacher_duty_projection_excludes_sensitive_and_domain_evidence_fields(): void
    {
        CarbonImmutable::setTestNow(
            CarbonImmutable::parse(
                '2026-09-09 10:00:00',
                'Africa/Nairobi'
            )
        );

        try {
            [$school, $user] = $this->teacherUser();

            $this->grantPermission(
                $user,
                'submit_teacher_duty_reports'
            );

            $this->teacherDutySettings(
                $school
            );

            $period = app(TeacherDutyRosterService::class)
                ->createPeriod(
                    (string) $school->id,
                    '2026-09-07',
                    '2026-09-11',
                    null,
                    (string) $user->id
                );

            $this->assignReporter(
                $period,
                $user
            );

            $report = app(
                TeacherDutyWeeklyReportService::class
            )->openReport(
                (string) $school->id,
                (string) $period->id,
                (string) $user->id
            );

            DB::table('teacher_duty_weekly_reports')
                ->where('id', $report->id)
                ->update([
                    'summary' => 'Sensitive weekly summary.',
                    'highlights' => 'Sensitive weekly highlights.',
                    'challenges' => 'Sensitive weekly challenges.',
                    'recommendations' => 'Sensitive recommendations.',
                    'updated_at' => now(),
                ]);

            $dashboard = app(TeacherPortalMobileService::class)
                ->dashboard($user);

            $projection = $dashboard['teacher_duty'];

            $this->assertSame(
                [
                    'duty_period_id',
                    'academic_week_id',
                    'week_number',
                    'today',
                    'occurrences_recorded',
                    'weekly_report',
                ],
                array_keys($projection)
            );

            $this->assertSame(
                [
                    'state',
                    'deadline_at',
                    'submitted_at',
                    'late',
                ],
                array_keys($projection['today'])
            );

            $this->assertSame(
                [
                    'id',
                    'state',
                ],
                array_keys($projection['weekly_report'])
            );

            $encoded = json_encode(
                $projection,
                JSON_THROW_ON_ERROR
            );

            foreach ([
                'school_id',
                'summary',
                'highlights',
                'challenges',
                'recommendations',
                'evidence_snapshot',
                'reviewed_by',
                'reviewed_at',
                'review_comment',
                'history',
                'occurrences',
            ] as $forbiddenField) {
                $this->assertArrayNotHasKey(
                    $forbiddenField,
                    $projection
                );

                $this->assertArrayNotHasKey(
                    $forbiddenField,
                    $projection['today']
                );

                $this->assertArrayNotHasKey(
                    $forbiddenField,
                    $projection['weekly_report']
                );
            }

            foreach ([
                'Sensitive weekly summary.',
                'Sensitive weekly highlights.',
                'Sensitive weekly challenges.',
                'Sensitive recommendations.',
            ] as $forbiddenValue) {
                $this->assertStringNotContainsString(
                    $forbiddenValue,
                    $encoded
                );
            }
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_dashboard_projects_changes_requested_weekly_report_state(): void
    {
        CarbonImmutable::setTestNow(
            CarbonImmutable::parse(
                '2026-09-09 10:00:00',
                'Africa/Nairobi'
            )
        );

        try {
            [$school, $user] = $this->teacherUser();

            $this->grantPermission(
                $user,
                'submit_teacher_duty_reports'
            );

            $this->teacherDutySettings(
                $school
            );

            $period = app(TeacherDutyRosterService::class)
                ->createPeriod(
                    (string) $school->id,
                    '2026-09-07',
                    '2026-09-11',
                    null,
                    (string) $user->id
                );

            $this->assignReporter(
                $period,
                $user
            );

            $weeklyReports = app(
                TeacherDutyWeeklyReportService::class
            );

            $report = $weeklyReports->openReport(
                (string) $school->id,
                (string) $period->id,
                (string) $user->id
            );

            DB::table('teacher_duty_periods')
                ->where('id', $period->id)
                ->update([
                    'active' => false,
                    'ended_by' => $user->id,
                    'ended_at' => now(),
                    'end_reason' => 'Duty period completed.',
                    'updated_at' => now(),
                ]);

            $weeklyReports->submitReport(
                (string) $school->id,
                (string) $report->id,
                (string) $user->id
            );

            $reviewer = $this->createTeacherUser(
                $school,
                $user->role
            );

            $this->grantPermission(
                $reviewer,
                'review_teacher_duty_reports'
            );

            $weeklyReports->reviewReport(
                (string) $school->id,
                (string) $report->id,
                'changes_requested',
                'Please clarify the reported challenges.',
                (string) $reviewer->id
            );

            DB::table('teacher_duty_periods')
                ->where('id', $period->id)
                ->update([
                    'active' => true,
                    'ended_by' => null,
                    'ended_at' => null,
                    'end_reason' => null,
                    'updated_at' => now(),
                ]);

            $reportsBefore = DB::table(
                'teacher_duty_weekly_reports'
            )->count();

            $historyBefore = DB::table(
                'teacher_duty_weekly_report_history'
            )->count();

            $dashboard = app(TeacherPortalMobileService::class)
                ->dashboard($user);

            $this->assertSame(
                (string) $report->id,
                $dashboard['teacher_duty']['weekly_report']['id']
            );

            $this->assertSame(
                'CHANGES_REQUESTED',
                $dashboard['teacher_duty']['weekly_report']['state']
            );

            $this->assertSame(
                $reportsBefore,
                DB::table(
                    'teacher_duty_weekly_reports'
                )->count()
            );

            $this->assertSame(
                $historyBefore,
                DB::table(
                    'teacher_duty_weekly_report_history'
                )->count()
            );
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_dashboard_projects_existing_weekly_report_authoritative_state(): void
    {
        CarbonImmutable::setTestNow(
            CarbonImmutable::parse(
                '2026-09-09 10:00:00',
                'Africa/Nairobi'
            )
        );

        try {
            [$school, $user] = $this->teacherUser();

            $this->grantPermission(
                $user,
                'submit_teacher_duty_reports'
            );

            $this->teacherDutySettings(
                $school
            );

            $period = app(TeacherDutyRosterService::class)
                ->createPeriod(
                    (string) $school->id,
                    '2026-09-07',
                    '2026-09-11',
                    null,
                    (string) $user->id
                );

            $this->assignReporter(
                $period,
                $user
            );

            $report = app(
                TeacherDutyWeeklyReportService::class
            )->openReport(
                (string) $school->id,
                (string) $period->id,
                (string) $user->id
            );

            $reportsBefore = DB::table(
                'teacher_duty_weekly_reports'
            )->count();

            $historyBefore = DB::table(
                'teacher_duty_weekly_report_history'
            )->count();

            $dashboard = app(TeacherPortalMobileService::class)
                ->dashboard($user);

            $this->assertSame(
                (string) $report->id,
                $dashboard['teacher_duty']['weekly_report']['id']
            );

            $this->assertSame(
                'DRAFT',
                $dashboard['teacher_duty']['weekly_report']['state']
            );

            $this->assertSame(
                $reportsBefore,
                DB::table(
                    'teacher_duty_weekly_reports'
                )->count()
            );

            $this->assertSame(
                $historyBefore,
                DB::table(
                    'teacher_duty_weekly_report_history'
                )->count()
            );
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_dashboard_projects_null_weekly_report_without_creating_report_or_history(): void
    {
        CarbonImmutable::setTestNow(
            CarbonImmutable::parse(
                '2026-09-09 10:00:00',
                'Africa/Nairobi'
            )
        );

        try {
            [$school, $user] = $this->teacherUser();

            $this->grantPermission(
                $user,
                'submit_teacher_duty_reports'
            );

            $this->teacherDutySettings(
                $school
            );

            $period = app(TeacherDutyRosterService::class)
                ->createPeriod(
                    (string) $school->id,
                    '2026-09-07',
                    '2026-09-11',
                    null,
                    (string) $user->id
                );

            $this->assignReporter(
                $period,
                $user
            );

            $reportsBefore = DB::table(
                'teacher_duty_weekly_reports'
            )->count();

            $historyBefore = DB::table(
                'teacher_duty_weekly_report_history'
            )->count();

            $dashboard = app(TeacherPortalMobileService::class)
                ->dashboard($user);

            $this->assertArrayHasKey(
                'weekly_report',
                $dashboard['teacher_duty']
            );

            $this->assertNull(
                $dashboard['teacher_duty']['weekly_report']
            );

            $this->assertSame(
                $reportsBefore,
                DB::table(
                    'teacher_duty_weekly_reports'
                )->count()
            );

            $this->assertSame(
                $historyBefore,
                DB::table(
                    'teacher_duty_weekly_report_history'
                )->count()
            );

            $this->assertSame(
                0,
                DB::table('teacher_duty_weekly_reports')
                    ->where('school_id', $school->id)
                    ->where('duty_period_id', $period->id)
                    ->count()
            );
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_occurrence_count_is_scoped_to_authorized_school_and_period(): void
    {
        CarbonImmutable::setTestNow(
            CarbonImmutable::parse(
                '2026-09-09 10:00:00',
                'Africa/Nairobi'
            )
        );

        try {
            [$school, $user] = $this->teacherUser();

            $this->grantPermission(
                $user,
                'submit_teacher_duty_reports'
            );

            $this->teacherDutySettings(
                $school
            );

            $authorizedPeriod = app(TeacherDutyRosterService::class)
                ->createPeriod(
                    (string) $school->id,
                    '2026-09-07',
                    '2026-09-11',
                    null,
                    (string) $user->id
                );

            $this->assignReporter(
                $authorizedPeriod,
                $user
            );

            $historicalPeriod = app(TeacherDutyRosterService::class)
                ->createPeriod(
                    (string) $school->id,
                    '2026-08-31',
                    '2026-09-04',
                    null,
                    (string) $user->id
                );

            $categoryId = (string) Str::uuid();

            DB::table(
                'teacher_duty_occurrence_categories'
            )->insert([
                'id' => $categoryId,
                'school_id' => $school->id,
                'code' => 'dashboard_test',
                'name' => 'Dashboard Test',
                'description' => null,
                'display_order' => 10,
                'is_canonical' => false,
                'created_by' => $user->id,
                'active' => true,
                'deactivated_by' => null,
                'deactivated_at' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $this->insertOccurrence(
                $school,
                (string) $authorizedPeriod->id,
                (string) $categoryId,
                $user,
                'First authorized-period occurrence.'
            );

            $this->insertOccurrence(
                $school,
                (string) $authorizedPeriod->id,
                (string) $categoryId,
                $user,
                'Second authorized-period occurrence.'
            );

            $this->insertOccurrence(
                $school,
                (string) $historicalPeriod->id,
                (string) $categoryId,
                $user,
                'Historical-period occurrence must not be counted.',
                '2026-09-03'
            );

            $dashboard = app(TeacherPortalMobileService::class)
                ->dashboard($user);

            $this->assertSame(
                2,
                $dashboard['teacher_duty']['occurrences_recorded']
            );
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_authorized_reporter_dashboard_projects_submitted_daily_state(): void
    {
        CarbonImmutable::setTestNow(
            CarbonImmutable::parse(
                '2026-09-09 10:00:00',
                'Africa/Nairobi'
            )
        );

        try {
            [$school, $user] = $this->teacherUser();

            $this->grantPermission(
                $user,
                'submit_teacher_duty_reports'
            );

            $this->teacherDutySettings(
                $school,
                '19:00:00',
                120
            );

            $period = app(TeacherDutyRosterService::class)
                ->createPeriod(
                    (string) $school->id,
                    '2026-09-07',
                    '2026-09-11',
                    null,
                    (string) $user->id
                );

            $this->assignReporter(
                $period,
                $user
            );

            $daily = app(TeacherDutyDailyReportService::class);

            $report = $daily->openReport(
                (string) $school->id,
                (string) $period->id,
                '2026-09-09',
                (string) $user->id
            );

            CarbonImmutable::setTestNow(
                CarbonImmutable::parse(
                    '2026-09-09 20:00:00',
                    'Africa/Nairobi'
                )
            );

            $submitted = $daily->submitReport(
                (string) $school->id,
                (string) $report->id,
                (string) $user->id
            );

            $dashboard = app(TeacherPortalMobileService::class)
                ->dashboard($user);

            $this->assertSame(
                'SUBMITTED',
                $dashboard['teacher_duty']['today']['state']
            );

            $this->assertNotNull(
                $dashboard['teacher_duty']['today']['submitted_at']
            );

            $this->assertSame(
                $submitted->submitted_at->toISOString(),
                CarbonImmutable::parse(
                    $dashboard['teacher_duty']['today']['submitted_at']
                )->toISOString()
            );

            $this->assertFalse(
                $dashboard['teacher_duty']['today']['late']
            );
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_authorized_reporter_dashboard_projects_existing_daily_not_started_state_without_creating_report(): void
    {
        CarbonImmutable::setTestNow(
            CarbonImmutable::parse(
                '2026-09-09 10:00:00',
                'Africa/Nairobi'
            )
        );

        try {
            [$school, $user] = $this->teacherUser();

            $this->grantPermission(
                $user,
                'submit_teacher_duty_reports'
            );

            $this->teacherDutySettings(
                $school,
                '19:00:00',
                120
            );

            $period = app(TeacherDutyRosterService::class)
                ->createPeriod(
                    (string) $school->id,
                    '2026-09-07',
                    '2026-09-11',
                    null,
                    (string) $user->id
                );

            $this->assignReporter(
                $period,
                $user
            );

            $reportsBefore = DB::table(
                'teacher_duty_daily_reports'
            )->count();

            $historyBefore = DB::table(
                'teacher_duty_daily_report_history'
            )->count();

            $dashboard = app(TeacherPortalMobileService::class)
                ->dashboard($user);

            $this->assertSame(
                'NOT_STARTED',
                $dashboard['teacher_duty']['today']['state']
            );

            $this->assertSame(
                '2026-09-09 21:00:00',
                CarbonImmutable::parse(
                    $dashboard['teacher_duty']['today']['deadline_at']
                )
                    ->setTimezone('Africa/Nairobi')
                    ->format('Y-m-d H:i:s')
            );

            $this->assertNull(
                $dashboard['teacher_duty']['today']['submitted_at']
            );

            $this->assertFalse(
                $dashboard['teacher_duty']['today']['late']
            );

            $this->assertSame(
                $reportsBefore,
                DB::table('teacher_duty_daily_reports')->count()
            );

            $this->assertSame(
                $historyBefore,
                DB::table(
                    'teacher_duty_daily_report_history'
                )->count()
            );
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_submit_permission_without_period_responsibility_does_not_expose_teacher_duty(): void
    {
        CarbonImmutable::setTestNow(
            CarbonImmutable::parse(
                '2026-09-09 10:00:00',
                'Africa/Nairobi'
            )
        );

        try {
            [$school, $user] = $this->teacherUser();

            $this->grantPermission(
                $user,
                'submit_teacher_duty_reports'
            );

            app(TeacherDutyRosterService::class)
                ->createPeriod(
                    (string) $school->id,
                    '2026-09-07',
                    '2026-09-11',
                    null,
                    (string) $user->id
                );

            $dashboard = app(TeacherPortalMobileService::class)
                ->dashboard($user);

            $this->assertNull(
                $dashboard['teacher_duty']
            );
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_another_teachers_current_duty_period_is_not_exposed(): void
    {
        CarbonImmutable::setTestNow(
            CarbonImmutable::parse(
                '2026-09-09 10:00:00',
                'Africa/Nairobi'
            )
        );

        try {
            $school = SchoolBuilder::create();

            $role = RoleBuilder::create([
                'role_name' => 'Teacher',
            ]);

            $user = $this->createTeacherUser(
                $school,
                $role
            );

            $other = $this->createTeacherUser(
                $school,
                $role
            );

            $this->grantPermission(
                $user,
                'submit_teacher_duty_reports'
            );

            $this->grantPermission(
                $other,
                'submit_teacher_duty_reports'
            );

            $period = app(TeacherDutyRosterService::class)
                ->createPeriod(
                    (string) $school->id,
                    '2026-09-07',
                    '2026-09-11',
                    null,
                    (string) $other->id
                );

            $this->assignReporter(
                $period,
                $other
            );

            $dashboard = app(TeacherPortalMobileService::class)
                ->dashboard($user);

            $this->assertNull(
                $dashboard['teacher_duty']
            );
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_cross_school_teacher_duty_data_is_not_exposed_or_counted(): void
    {
        CarbonImmutable::setTestNow(
            CarbonImmutable::parse(
                '2026-09-09 10:00:00',
                'Africa/Nairobi'
            )
        );

        try {
            [$school, $user] = $this->teacherUser();

            $this->grantPermission(
                $user,
                'submit_teacher_duty_reports'
            );

            $this->teacherDutySettings(
                $school
            );

            $period = app(TeacherDutyRosterService::class)
                ->createPeriod(
                    (string) $school->id,
                    '2026-09-07',
                    '2026-09-11',
                    null,
                    (string) $user->id
                );

            $this->assignReporter(
                $period,
                $user
            );

            $otherSchool = SchoolBuilder::create();

            $otherRole = RoleBuilder::create([
                'role_name' => 'Teacher',
            ]);

            $otherUser = $this->createTeacherUser(
                $otherSchool,
                $otherRole
            );

            $this->grantPermission(
                $otherUser,
                'submit_teacher_duty_reports'
            );

            $this->teacherDutySettings(
                $otherSchool
            );

            $otherPeriod = app(TeacherDutyRosterService::class)
                ->createPeriod(
                    (string) $otherSchool->id,
                    '2026-09-07',
                    '2026-09-11',
                    null,
                    (string) $otherUser->id
                );

            $this->assignReporter(
                $otherPeriod,
                $otherUser
            );

            $otherReport = app(
                TeacherDutyWeeklyReportService::class
            )->openReport(
                (string) $otherSchool->id,
                (string) $otherPeriod->id,
                (string) $otherUser->id
            );

            $categoryId = (string) Str::uuid();

            DB::table(
                'teacher_duty_occurrence_categories'
            )->insert([
                'id' => $categoryId,
                'school_id' => $otherSchool->id,
                'code' => 'cross_school_dashboard_test',
                'name' => 'Cross School Dashboard Test',
                'description' => null,
                'display_order' => 10,
                'is_canonical' => false,
                'created_by' => $otherUser->id,
                'active' => true,
                'deactivated_by' => null,
                'deactivated_at' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $otherOccurrenceId = $this->insertOccurrence(
                $otherSchool,
                (string) $otherPeriod->id,
                $categoryId,
                $otherUser,
                'Foreign-school occurrence must not leak.'
            );

            $dashboard = app(TeacherPortalMobileService::class)
                ->dashboard($user);

            $this->assertSame(
                (string) $period->id,
                $dashboard['teacher_duty']['duty_period_id']
            );

            $this->assertSame(
                0,
                $dashboard['teacher_duty']['occurrences_recorded']
            );

            $this->assertNull(
                $dashboard['teacher_duty']['weekly_report']
            );

            $projection = json_encode(
                $dashboard['teacher_duty'],
                JSON_THROW_ON_ERROR
            );

            $this->assertStringNotContainsString(
                (string) $otherPeriod->id,
                $projection
            );

            $this->assertStringNotContainsString(
                (string) $otherReport->id,
                $projection
            );

            $this->assertStringNotContainsString(
                $otherOccurrenceId,
                $projection
            );

            $this->assertStringNotContainsString(
                (string) $otherSchool->id,
                $projection
            );
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_multiple_authorized_current_periods_fail_closed_without_arbitrary_selection(): void
    {
        CarbonImmutable::setTestNow(
            CarbonImmutable::parse(
                '2026-09-09 10:00:00',
                'Africa/Nairobi'
            )
        );

        try {
            [$school, $user] = $this->teacherUser();

            $this->grantPermission(
                $user,
                'submit_teacher_duty_reports'
            );

            $first = app(TeacherDutyRosterService::class)
                ->createPeriod(
                    (string) $school->id,
                    '2026-09-07',
                    '2026-09-11',
                    null,
                    (string) $user->id
                );

            $second = app(TeacherDutyRosterService::class)
                ->createPeriod(
                    (string) $school->id,
                    '2026-09-08',
                    '2026-09-12',
                    null,
                    (string) $user->id
                );

            $this->assignReporter(
                $first,
                $user
            );

            $this->assignReporter(
                $second,
                $user
            );

            $dashboard = app(TeacherPortalMobileService::class)
                ->dashboard($user);

            $this->assertNull(
                $dashboard['teacher_duty']
            );
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_dashboard_contains_null_teacher_duty_when_teacher_has_no_authorized_current_duty_period(): void
    {
        $school = SchoolBuilder::create();

        $role = RoleBuilder::create([
            'role_name' => 'Teacher',
        ]);

        $user = UserBuilder::create(
            $school,
            $role
        );

        TeacherBuilder::create(
            $school,
            $user
        );

        $user = User::with('role')
            ->findOrFail($user->id);

        $dashboard = app(TeacherPortalMobileService::class)
            ->dashboard($user);

        $this->assertArrayHasKey(
            'teacher_duty',
            $dashboard
        );

        $this->assertNull(
            $dashboard['teacher_duty']
        );

        foreach ([
            'teacher',
            'current_period',
            'assignments',
            'todays_timetable',
            'analytics',
            'announcements',
            'recent_communications',
            'last_refreshed_at',
        ] as $existingKey) {
            $this->assertArrayHasKey(
                $existingKey,
                $dashboard,
                "Existing dashboard key [{$existingKey}] disappeared."
            );
        }
    }

    private function insertOccurrence(
        object $school,
        string $periodId,
        string $categoryId,
        User $actor,
        string $description,
        string $occurrenceDate = '2026-09-09'
    ): string {
        $id = (string) Str::uuid();

        DB::table('teacher_duty_occurrences')->insert([
            'id' => $id,
            'school_id' => $school->id,
            'duty_period_id' => $periodId,
            'occurrence_category_id' => $categoryId,
            'occurrence_date' => $occurrenceDate,
            'occurrence_time' => '10:15:00',
            'description' => $description,
            'recorded_by' => $actor->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function teacherDutySettings(
        object $school,
        string $deadline = '17:00:00',
        int $graceMinutes = 120
    ): void {
        DB::table('school_settings')->insert([
            'school_id' => $school->id,
            'teacher_duty_report_deadline_time' => $deadline,
            'teacher_duty_report_grace_minutes' => $graceMinutes,
        ]);
    }

    public function test_dashboard_http_resource_exposes_existing_keys_and_authorized_teacher_duty_projection(): void
    {
        [$school, $user] = $this->teacherUser();

        $this->grantPermission($user, 'access_teacher_portal');
        $this->grantPermission($user, 'submit_teacher_duty_reports');

        $this->teacherDutySettings($school);

        $period = app(TeacherDutyRosterService::class)->createPeriod(
            (string) $school->id,
            now()->subDay()->toDateString(),
            now()->addDay()->toDateString(),
            null,
            (string) $user->id
        );

        $this->assignReporter($period, $user);

        $token = JWTAuth::fromUser($user);

        $response = $this
            ->withToken($token)
            ->getJson('/api/teacher/dashboard');

        $response
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'data' => [
                    'teacher',
                    'current_period',
                    'assignments',
                    'todays_timetable',
                    'analytics',
                    'announcements',
                    'recent_communications',
                    'teacher_duty' => [
                        'duty_period_id',
                        'academic_week_id',
                        'week_number',
                        'today' => [
                            'state',
                            'deadline_at',
                            'submitted_at',
                            'late',
                        ],
                        'occurrences_recorded',
                        'weekly_report',
                    ],
                    'last_refreshed_at',
                ],
            ])
            ->assertJsonPath(
                'data.teacher_duty.duty_period_id',
                (string) $period->id
            );
    }

    public function test_access_teacher_portal_alone_does_not_grant_teacher_duty_dashboard_data(): void
    {
        [$school, $user] = $this->teacherUser();

        $this->grantPermission($user, 'access_teacher_portal');

        app(TeacherDutyRosterService::class)->createPeriod(
            (string) $school->id,
            now()->subDay()->toDateString(),
            now()->addDay()->toDateString(),
            null,
            (string) $user->id
        );

        $token = JWTAuth::fromUser($user);

        $this
            ->withToken($token)
            ->getJson('/api/teacher/dashboard')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.teacher_duty', null);
    }

    private function teacherUser(): array
    {
        $school = SchoolBuilder::create();

        $role = RoleBuilder::create([
            'role_name' => 'Teacher',
        ]);

        return [
            $school,
            $this->createTeacherUser(
                $school,
                $role
            ),
        ];
    }

    private function createTeacherUser(
        object $school,
        object $role
    ): User {
        $user = UserBuilder::create(
            $school,
            $role
        );

        TeacherBuilder::create(
            $school,
            $user
        );

        return User::with('role')
            ->findOrFail($user->id);
    }

    private function assignReporter(
        TeacherDutyPeriod $period,
        User $user
    ): TeacherDutyAssignment {
        $teacher = Teacher::query()
            ->withoutGlobalScopes()
            ->where('school_id', $user->school_id)
            ->where('user_id', $user->id)
            ->where('active', true)
            ->where('is_deleted', false)
            ->firstOrFail();

        return app(TeacherDutyRosterService::class)
            ->assignTeacher(
                (string) $user->school_id,
                (string) $period->id,
                (string) $teacher->id,
                (string) $user->id
            );
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
                "Required migrated permission [{$permissionName}] was not found."
            );
        }

        if (! $user->role_id) {
            throw new \RuntimeException(
                'Teacher Duty dashboard test actor has no primary role.'
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
