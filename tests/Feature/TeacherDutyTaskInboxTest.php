<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Assessment\AssessmentTypeService;
use App\Services\Assessment\ExamLearningAreaService;
use App\Services\Assessment\ExamPaperService;
use App\Services\Assessment\ExamService;
use App\Services\Auth\AuthContextService;
use App\Services\TeacherDuty\TeacherDutyDailyReportService;
use App\Services\TeacherDuty\TeacherDutyRosterService;
use App\Services\TeacherDuty\TeacherDutyWeeklyReportService;
use App\Services\TeacherPortal\TeacherWorkflowService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\Database\AcademicYearBuilder;
use Tests\Support\Database\GradeBuilder;
use Tests\Support\Database\LearningAreaBuilder;
use Tests\Support\Database\RoleBuilder;
use Tests\Support\Database\SchoolBuilder;
use Tests\Support\Database\StreamBuilder;
use Tests\Support\Database\TeacherBuilder;
use Tests\Support\Database\TermBuilder;
use Tests\Support\Database\UserBuilder;
use Tests\TestCase;

class TeacherDutyTaskInboxTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'jwt.secret' => str_repeat('a', 64),
        ]);
    }

    public function test_teacher_tasks_endpoint_exposes_teacher_duty_task_without_creating_report(): void
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

            $teacher = TeacherBuilder::create(
                $school,
                $user
            );

            $user = User::with('role')->findOrFail($user->id);

            $this->grantPermission(
                $user,
                'access_teacher_portal'
            );

            $this->grantPermission(
                $user,
                'submit_teacher_duty_reports'
            );

            $this->teacherDutySettings(
                (string) $school->id
            );

            $period = app(TeacherDutyRosterService::class)
                ->createPeriod(
                    (string) $school->id,
                    '2026-09-07',
                    '2026-09-11',
                    null,
                    (string) $user->id
                );

            app(TeacherDutyRosterService::class)
                ->assignTeacher(
                    (string) $school->id,
                    (string) $period->id,
                    (string) $teacher->id,
                    (string) $user->id
                );

            $reportsBefore = DB::table(
                'teacher_duty_daily_reports'
            )->count();

            $historyBefore = DB::table(
                'teacher_duty_daily_report_history'
            )->count();

            $token = JWTAuth::fromUser($user);

            $response = $this
                ->withToken($token)
                ->getJson('/api/teacher/tasks');

            $response
                ->assertOk()
                ->assertJsonPath('success', true)
                ->assertJsonFragment([
                    'task_type' => 'teacher_duty_daily_report_due',
                    'title' => 'Teacher duty daily report',
                    'priority' => 'normal',
                    'entity_reference' => 'teacher_duty_daily:'
                        .$period->id
                        .':2026-09-09',
                    'status' => 'NOT_STARTED',
                ]);

            $tasks = collect($response->json('data'));

            $task = $tasks->firstWhere(
                'task_type',
                'teacher_duty_daily_report_due'
            );

            $this->assertNotNull($task);

            $this->assertSame(
                [
                    'task_type',
                    'title',
                    'priority',
                    'entity_reference',
                    'status',
                    'deep_link',
                ],
                array_keys($task)
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

    public function test_authorized_reporter_receives_today_teacher_duty_task_without_creating_report(): void
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

            $teacher = TeacherBuilder::create(
                $school,
                $user
            );

            $user = User::with('role')->findOrFail($user->id);

            $this->grantPermission(
                $user,
                'submit_teacher_duty_reports'
            );

            $this->teacherDutySettings(
                (string) $school->id
            );

            $period = app(TeacherDutyRosterService::class)
                ->createPeriod(
                    (string) $school->id,
                    '2026-09-07',
                    '2026-09-11',
                    null,
                    (string) $user->id
                );

            app(TeacherDutyRosterService::class)
                ->assignTeacher(
                    (string) $school->id,
                    (string) $period->id,
                    (string) $teacher->id,
                    (string) $user->id
                );

            $reportsBefore = DB::table(
                'teacher_duty_daily_reports'
            )->count();

            $historyBefore = DB::table(
                'teacher_duty_daily_report_history'
            )->count();

            $tasks = app(TeacherWorkflowService::class)
                ->tasks($user);

            $task = $tasks->firstWhere(
                'task_type',
                'teacher_duty_daily_report_due'
            );

            $this->assertNotNull($task);

            $this->assertSame(
                'teacher_duty_daily:'.$period->id.':2026-09-09',
                $task['entity_reference']
            );

            $this->assertSame(
                'NOT_STARTED',
                $task['status']
            );

            $this->assertSame(
                'normal',
                $task['priority']
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

    public function test_authorized_reporter_receives_daily_due_task_for_existing_draft(): void
    {
        CarbonImmutable::setTestNow(
            CarbonImmutable::parse(
                '2026-09-09 18:00:00',
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

            $teacher = TeacherBuilder::create(
                $school,
                $user
            );

            $user = User::with('role')->findOrFail($user->id);

            $this->grantPermission(
                $user,
                'submit_teacher_duty_reports'
            );

            $this->teacherDutySettings(
                (string) $school->id
            );

            $period = app(TeacherDutyRosterService::class)
                ->createPeriod(
                    (string) $school->id,
                    '2026-09-07',
                    '2026-09-11',
                    null,
                    (string) $user->id
                );

            app(TeacherDutyRosterService::class)
                ->assignTeacher(
                    (string) $school->id,
                    (string) $period->id,
                    (string) $teacher->id,
                    (string) $user->id
                );

            $report = app(TeacherDutyDailyReportService::class)
                ->openReport(
                    (string) $school->id,
                    (string) $period->id,
                    '2026-09-09',
                    (string) $user->id
                );

            $reportsBefore = DB::table(
                'teacher_duty_daily_reports'
            )->count();

            $historyBefore = DB::table(
                'teacher_duty_daily_report_history'
            )->count();

            $tasks = app(TeacherWorkflowService::class)
                ->tasks($user);

            $task = $tasks->firstWhere(
                'task_type',
                'teacher_duty_daily_report_due'
            );

            $this->assertNotNull($task);

            $this->assertSame(
                'teacher_duty_daily:'.$period->id.':2026-09-09',
                $task['entity_reference']
            );

            $this->assertSame('DRAFT', $task['status']);
            $this->assertSame('normal', $task['priority']);

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

            $this->assertDatabaseHas(
                'teacher_duty_daily_reports',
                [
                    'id' => $report->id,
                    'status' => 'draft',
                ]
            );
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_authorized_reporter_receives_high_priority_overdue_daily_task(): void
    {
        CarbonImmutable::setTestNow(
            CarbonImmutable::parse(
                '2026-09-09 21:00:01',
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

            $teacher = TeacherBuilder::create(
                $school,
                $user
            );

            $user = User::with('role')->findOrFail($user->id);

            $this->grantPermission(
                $user,
                'submit_teacher_duty_reports'
            );

            $this->teacherDutySettings(
                (string) $school->id
            );

            $period = app(TeacherDutyRosterService::class)
                ->createPeriod(
                    (string) $school->id,
                    '2026-09-07',
                    '2026-09-11',
                    null,
                    (string) $user->id
                );

            app(TeacherDutyRosterService::class)
                ->assignTeacher(
                    (string) $school->id,
                    (string) $period->id,
                    (string) $teacher->id,
                    (string) $user->id
                );

            $reportsBefore = DB::table(
                'teacher_duty_daily_reports'
            )->count();

            $historyBefore = DB::table(
                'teacher_duty_daily_report_history'
            )->count();

            $tasks = app(TeacherWorkflowService::class)
                ->tasks($user);

            $task = $tasks->firstWhere(
                'task_type',
                'teacher_duty_daily_report_overdue'
            );

            $this->assertNotNull($task);

            $this->assertSame(
                'teacher_duty_daily:'.$period->id.':2026-09-09',
                $task['entity_reference']
            );

            $this->assertSame('OVERDUE', $task['status']);
            $this->assertSame('high', $task['priority']);

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

    public function test_submitted_daily_report_does_not_generate_teacher_duty_task(): void
    {
        CarbonImmutable::setTestNow(
            CarbonImmutable::parse(
                '2026-09-09 18:00:00',
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

            $teacher = TeacherBuilder::create(
                $school,
                $user
            );

            $user = User::with('role')->findOrFail($user->id);

            $this->grantPermission(
                $user,
                'submit_teacher_duty_reports'
            );

            $this->teacherDutySettings(
                (string) $school->id
            );

            $period = app(TeacherDutyRosterService::class)
                ->createPeriod(
                    (string) $school->id,
                    '2026-09-07',
                    '2026-09-11',
                    null,
                    (string) $user->id
                );

            app(TeacherDutyRosterService::class)
                ->assignTeacher(
                    (string) $school->id,
                    (string) $period->id,
                    (string) $teacher->id,
                    (string) $user->id
                );

            $report = app(TeacherDutyDailyReportService::class)
                ->openReport(
                    (string) $school->id,
                    (string) $period->id,
                    '2026-09-09',
                    (string) $user->id
                );

            app(TeacherDutyDailyReportService::class)
                ->submitReport(
                    (string) $school->id,
                    (string) $report->id,
                    (string) $user->id
                );

            $reportsBefore = DB::table(
                'teacher_duty_daily_reports'
            )->count();

            $historyBefore = DB::table(
                'teacher_duty_daily_report_history'
            )->count();

            $tasks = app(TeacherWorkflowService::class)
                ->tasks($user);

            $dailyTasks = $tasks->filter(
                fn (array $task) => str_starts_with(
                    $task['task_type'],
                    'teacher_duty_daily_'
                )
            );

            $this->assertCount(0, $dailyTasks);

            $this->assertDatabaseHas(
                'teacher_duty_daily_reports',
                [
                    'id' => $report->id,
                    'status' => 'submitted',
                ]
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

    public function test_authorized_reporter_receives_weekly_due_task_for_ended_period_draft(): void
    {
        CarbonImmutable::setTestNow(
            CarbonImmutable::parse(
                '2026-09-12 08:00:00',
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

            $teacher = TeacherBuilder::create(
                $school,
                $user
            );

            $user = User::with('role')->findOrFail($user->id);

            $this->grantPermission(
                $user,
                'submit_teacher_duty_reports'
            );

            $this->teacherDutySettings(
                (string) $school->id
            );

            $roster = app(TeacherDutyRosterService::class);

            $period = $roster->createPeriod(
                (string) $school->id,
                '2026-09-07',
                '2026-09-11',
                null,
                (string) $user->id
            );

            $roster->assignTeacher(
                (string) $school->id,
                (string) $period->id,
                (string) $teacher->id,
                (string) $user->id
            );

            $report = app(TeacherDutyWeeklyReportService::class)
                ->openReport(
                    (string) $school->id,
                    (string) $period->id,
                    (string) $user->id
                );

            $roster->endPeriod(
                (string) $school->id,
                (string) $period->id,
                (string) $user->id,
                'Duty week completed'
            );

            $reportsBefore = DB::table(
                'teacher_duty_weekly_reports'
            )->count();

            $historyBefore = DB::table(
                'teacher_duty_weekly_report_history'
            )->count();

            $tasks = app(TeacherWorkflowService::class)
                ->tasks($user);

            $task = $tasks->firstWhere(
                'task_type',
                'teacher_duty_weekly_report_due'
            );

            $this->assertNotNull($task);

            $this->assertSame(
                'teacher_duty_weekly:'.$report->id,
                $task['entity_reference']
            );

            $this->assertSame('draft', $task['status']);
            $this->assertSame('normal', $task['priority']);

            $this->assertSame(
                $reportsBefore,
                DB::table('teacher_duty_weekly_reports')->count()
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

    public function test_authorized_reporter_receives_high_priority_weekly_changes_requested_task(): void
    {
        CarbonImmutable::setTestNow(
            CarbonImmutable::parse(
                '2026-09-12 08:00:00',
                'Africa/Nairobi'
            )
        );

        try {
            $school = SchoolBuilder::create();

            $teacherRole = RoleBuilder::create([
                'role_name' => 'Teacher',
            ]);

            $reporter = UserBuilder::create(
                $school,
                $teacherRole
            );

            $teacher = TeacherBuilder::create(
                $school,
                $reporter
            );

            $reporter = User::with('role')->findOrFail(
                $reporter->id
            );

            $this->grantPermission(
                $reporter,
                'submit_teacher_duty_reports'
            );

            $reviewerRole = RoleBuilder::create([
                'role_name' => 'Duty Reviewer',
            ]);

            $reviewer = UserBuilder::create(
                $school,
                $reviewerRole
            );

            $reviewer = User::with('role')->findOrFail(
                $reviewer->id
            );

            $this->grantPermission(
                $reviewer,
                'review_teacher_duty_reports'
            );

            $this->teacherDutySettings(
                (string) $school->id
            );

            $roster = app(TeacherDutyRosterService::class);

            $period = $roster->createPeriod(
                (string) $school->id,
                '2026-09-07',
                '2026-09-11',
                null,
                (string) $reporter->id
            );

            $roster->assignTeacher(
                (string) $school->id,
                (string) $period->id,
                (string) $teacher->id,
                (string) $reporter->id
            );

            $weekly = app(
                TeacherDutyWeeklyReportService::class
            );

            $report = $weekly->openReport(
                (string) $school->id,
                (string) $period->id,
                (string) $reporter->id
            );

            $roster->endPeriod(
                (string) $school->id,
                (string) $period->id,
                (string) $reporter->id,
                'Duty week completed'
            );

            $weekly->submitReport(
                (string) $school->id,
                (string) $report->id,
                (string) $reporter->id
            );

            $weekly->reviewReport(
                (string) $school->id,
                (string) $report->id,
                'changes_requested',
                'Please clarify the reported challenges.',
                (string) $reviewer->id
            );

            $reportsBefore = DB::table(
                'teacher_duty_weekly_reports'
            )->count();

            $historyBefore = DB::table(
                'teacher_duty_weekly_report_history'
            )->count();

            $tasks = app(TeacherWorkflowService::class)
                ->tasks($reporter);

            $task = $tasks->firstWhere(
                'task_type',
                'teacher_duty_weekly_changes_requested'
            );

            $this->assertNotNull($task);

            $this->assertSame(
                'teacher_duty_weekly:'.$report->id,
                $task['entity_reference']
            );

            $this->assertSame(
                'changes_requested',
                $task['status']
            );

            $this->assertSame('high', $task['priority']);

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

    #[DataProvider('nonActionableWeeklyStatuses')]
    public function test_non_actionable_weekly_status_does_not_generate_reporter_task(
        string $targetStatus
    ): void {
        CarbonImmutable::setTestNow(
            CarbonImmutable::parse(
                '2026-09-12 08:00:00',
                'Africa/Nairobi'
            )
        );

        try {
            $school = SchoolBuilder::create();

            $teacherRole = RoleBuilder::create([
                'role_name' => 'Teacher',
            ]);

            $reporter = UserBuilder::create(
                $school,
                $teacherRole
            );

            $teacher = TeacherBuilder::create(
                $school,
                $reporter
            );

            $reporter = User::with('role')->findOrFail(
                $reporter->id
            );

            $this->grantPermission(
                $reporter,
                'submit_teacher_duty_reports'
            );

            $this->teacherDutySettings(
                (string) $school->id
            );

            $roster = app(TeacherDutyRosterService::class);

            $period = $roster->createPeriod(
                (string) $school->id,
                '2026-09-07',
                '2026-09-11',
                null,
                (string) $reporter->id
            );

            $roster->assignTeacher(
                (string) $school->id,
                (string) $period->id,
                (string) $teacher->id,
                (string) $reporter->id
            );

            $weekly = app(
                TeacherDutyWeeklyReportService::class
            );

            $report = $weekly->openReport(
                (string) $school->id,
                (string) $period->id,
                (string) $reporter->id
            );

            $roster->endPeriod(
                (string) $school->id,
                (string) $period->id,
                (string) $reporter->id,
                'Duty week completed'
            );

            $weekly->submitReport(
                (string) $school->id,
                (string) $report->id,
                (string) $reporter->id
            );

            if (in_array(
                $targetStatus,
                ['approved', 'rejected'],
                true
            )) {
                $reviewerRole = RoleBuilder::create([
                    'role_name' => 'Duty Reviewer',
                ]);

                $reviewer = UserBuilder::create(
                    $school,
                    $reviewerRole
                );

                $reviewer = User::with('role')->findOrFail(
                    $reviewer->id
                );

                $this->grantPermission(
                    $reviewer,
                    'review_teacher_duty_reports'
                );

                $weekly->reviewReport(
                    (string) $school->id,
                    (string) $report->id,
                    $targetStatus,
                    $targetStatus === 'rejected'
                        ? 'The weekly report requires substantial correction.'
                        : null,
                    (string) $reviewer->id
                );
            }

            $reportsBefore = DB::table(
                'teacher_duty_weekly_reports'
            )->count();

            $historyBefore = DB::table(
                'teacher_duty_weekly_report_history'
            )->count();

            $tasks = app(TeacherWorkflowService::class)
                ->tasks($reporter);

            $weeklyTask = $tasks->first(
                fn (array $task): bool => str_starts_with(
                    $task['task_type'],
                    'teacher_duty_weekly_'
                )
            );

            $this->assertNull($weeklyTask);

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

    public static function nonActionableWeeklyStatuses(): array
    {
        return [
            'submitted' => ['submitted'],
            'approved' => ['approved'],
            'rejected' => ['rejected'],
        ];
    }

    public function test_assigned_teacher_without_reporter_permission_receives_no_teacher_duty_task(): void
    {
        CarbonImmutable::setTestNow(
            CarbonImmutable::parse(
                '2026-09-09 12:00:00',
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

            $teacher = TeacherBuilder::create(
                $school,
                $user
            );

            $user = User::with('role')->findOrFail($user->id);

            $submitPermissionId = DB::table('permissions')
                ->where(
                    'permission_name',
                    'submit_teacher_duty_reports'
                )
                ->value('id');

            $this->assertNotNull($submitPermissionId);

            DB::table('role_permissions')
                ->where('role_id', $user->role_id)
                ->where(
                    'permission_id',
                    $submitPermissionId
                )
                ->delete();

            $this->assertFalse(
                app(AuthContextService::class)
                    ->hasPermission(
                        $user,
                        'submit_teacher_duty_reports'
                    )
            );

            $this->teacherDutySettings(
                (string) $school->id
            );

            $roster = app(TeacherDutyRosterService::class);

            $period = $roster->createPeriod(
                (string) $school->id,
                '2026-09-07',
                '2026-09-11',
                null,
                (string) $user->id
            );

            $roster->assignTeacher(
                (string) $school->id,
                (string) $period->id,
                (string) $teacher->id,
                (string) $user->id
            );

            $dailyReportsBefore = DB::table(
                'teacher_duty_daily_reports'
            )->count();

            $dailyHistoryBefore = DB::table(
                'teacher_duty_daily_report_history'
            )->count();

            $weeklyReportsBefore = DB::table(
                'teacher_duty_weekly_reports'
            )->count();

            $weeklyHistoryBefore = DB::table(
                'teacher_duty_weekly_report_history'
            )->count();

            $tasks = app(TeacherWorkflowService::class)
                ->tasks($user);

            $teacherDutyTask = $tasks->first(
                fn (array $task): bool => str_starts_with(
                    $task['task_type'],
                    'teacher_duty_'
                )
            );

            $this->assertNull($teacherDutyTask);

            $this->assertSame(
                $dailyReportsBefore,
                DB::table(
                    'teacher_duty_daily_reports'
                )->count()
            );

            $this->assertSame(
                $dailyHistoryBefore,
                DB::table(
                    'teacher_duty_daily_report_history'
                )->count()
            );

            $this->assertSame(
                $weeklyReportsBefore,
                DB::table(
                    'teacher_duty_weekly_reports'
                )->count()
            );

            $this->assertSame(
                $weeklyHistoryBefore,
                DB::table(
                    'teacher_duty_weekly_report_history'
                )->count()
            );
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_teacher_with_reporter_permission_but_no_assignment_receives_no_teacher_duty_task(): void
    {
        CarbonImmutable::setTestNow(
            CarbonImmutable::parse(
                '2026-09-09 12:00:00',
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

            $user = User::with('role')->findOrFail($user->id);

            $this->grantPermission(
                $user,
                'submit_teacher_duty_reports'
            );

            $this->teacherDutySettings(
                (string) $school->id
            );

            $roster = app(TeacherDutyRosterService::class);

            $roster->createPeriod(
                (string) $school->id,
                '2026-09-07',
                '2026-09-11',
                null,
                (string) $user->id
            );

            $dailyReportsBefore = DB::table(
                'teacher_duty_daily_reports'
            )->count();

            $dailyHistoryBefore = DB::table(
                'teacher_duty_daily_report_history'
            )->count();

            $weeklyReportsBefore = DB::table(
                'teacher_duty_weekly_reports'
            )->count();

            $weeklyHistoryBefore = DB::table(
                'teacher_duty_weekly_report_history'
            )->count();

            $tasks = app(TeacherWorkflowService::class)
                ->tasks($user);

            $teacherDutyTask = $tasks->first(
                fn (array $task): bool => str_starts_with(
                    $task['task_type'],
                    'teacher_duty_'
                )
            );

            $this->assertNull($teacherDutyTask);

            $this->assertSame(
                $dailyReportsBefore,
                DB::table(
                    'teacher_duty_daily_reports'
                )->count()
            );

            $this->assertSame(
                $dailyHistoryBefore,
                DB::table(
                    'teacher_duty_daily_report_history'
                )->count()
            );

            $this->assertSame(
                $weeklyReportsBefore,
                DB::table(
                    'teacher_duty_weekly_reports'
                )->count()
            );

            $this->assertSame(
                $weeklyHistoryBefore,
                DB::table(
                    'teacher_duty_weekly_report_history'
                )->count()
            );
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_ended_duty_period_does_not_generate_new_daily_work(): void
    {
        CarbonImmutable::setTestNow(
            CarbonImmutable::parse(
                '2026-09-09 12:00:00',
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

            $teacher = TeacherBuilder::create(
                $school,
                $user
            );

            $user = User::with('role')->findOrFail($user->id);

            $this->grantPermission(
                $user,
                'submit_teacher_duty_reports'
            );

            $this->teacherDutySettings(
                (string) $school->id
            );

            $roster = app(TeacherDutyRosterService::class);

            $period = $roster->createPeriod(
                (string) $school->id,
                '2026-09-07',
                '2026-09-11',
                null,
                (string) $user->id
            );

            $roster->assignTeacher(
                (string) $school->id,
                (string) $period->id,
                (string) $teacher->id,
                (string) $user->id
            );

            $roster->endPeriod(
                (string) $school->id,
                (string) $period->id,
                (string) $user->id,
                'Duty period ended early'
            );

            $dailyReportsBefore = DB::table(
                'teacher_duty_daily_reports'
            )->count();

            $dailyHistoryBefore = DB::table(
                'teacher_duty_daily_report_history'
            )->count();

            $tasks = app(TeacherWorkflowService::class)
                ->tasks($user);

            $dailyTask = $tasks->first(
                fn (array $task): bool => str_starts_with(
                    $task['task_type'],
                    'teacher_duty_daily_'
                )
            );

            $this->assertNull($dailyTask);

            $this->assertSame(
                $dailyReportsBefore,
                DB::table(
                    'teacher_duty_daily_reports'
                )->count()
            );

            $this->assertSame(
                $dailyHistoryBefore,
                DB::table(
                    'teacher_duty_daily_report_history'
                )->count()
            );
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_teacher_duty_tasks_are_isolated_to_the_requesting_school(): void
    {
        CarbonImmutable::setTestNow(
            CarbonImmutable::parse(
                '2026-09-09 12:00:00',
                'Africa/Nairobi'
            )
        );

        try {
            $schoolA = SchoolBuilder::create();
            $schoolB = SchoolBuilder::create();

            $roleA = RoleBuilder::create([
                'role_name' => 'Teacher',
            ]);

            $roleB = RoleBuilder::create([
                'role_name' => 'Teacher',
            ]);

            $userA = UserBuilder::create(
                $schoolA,
                $roleA
            );

            $userB = UserBuilder::create(
                $schoolB,
                $roleB
            );

            $teacherA = TeacherBuilder::create(
                $schoolA,
                $userA
            );

            $teacherB = TeacherBuilder::create(
                $schoolB,
                $userB
            );

            $userA = User::with('role')->findOrFail($userA->id);
            $userB = User::with('role')->findOrFail($userB->id);

            $this->grantPermission(
                $userA,
                'submit_teacher_duty_reports'
            );

            $this->grantPermission(
                $userB,
                'submit_teacher_duty_reports'
            );

            $this->teacherDutySettings(
                (string) $schoolA->id
            );

            $this->teacherDutySettings(
                (string) $schoolB->id
            );

            $roster = app(TeacherDutyRosterService::class);

            $periodA = $roster->createPeriod(
                (string) $schoolA->id,
                '2026-09-07',
                '2026-09-11',
                null,
                (string) $userA->id
            );

            $roster->assignTeacher(
                (string) $schoolA->id,
                (string) $periodA->id,
                (string) $teacherA->id,
                (string) $userA->id
            );

            $periodB = $roster->createPeriod(
                (string) $schoolB->id,
                '2026-09-07',
                '2026-09-11',
                null,
                (string) $userB->id
            );

            $roster->assignTeacher(
                (string) $schoolB->id,
                (string) $periodB->id,
                (string) $teacherB->id,
                (string) $userB->id
            );

            $reportsBefore = DB::table(
                'teacher_duty_daily_reports'
            )->count();

            $historyBefore = DB::table(
                'teacher_duty_daily_report_history'
            )->count();

            $tasks = app(TeacherWorkflowService::class)
                ->tasks($userA);

            $ownReference =
                'teacher_duty_daily:'
                .$periodA->id
                .':2026-09-09';

            $foreignReference =
                'teacher_duty_daily:'
                .$periodB->id
                .':2026-09-09';

            $this->assertTrue(
                $tasks->contains(
                    fn (array $task): bool => $task['entity_reference'] === $ownReference
                )
            );

            $this->assertFalse(
                $tasks->contains(
                    fn (array $task): bool => $task['entity_reference'] === $foreignReference
                )
            );

            $this->assertSame(
                1,
                $tasks->filter(
                    fn (array $task): bool => str_starts_with(
                        $task['task_type'],
                        'teacher_duty_'
                    )
                )->count()
            );

            $this->assertSame(
                $reportsBefore,
                DB::table(
                    'teacher_duty_daily_reports'
                )->count()
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

    private function teacherDutySettings(string $schoolId): void
    {
        DB::table('school_settings')->insert([
            'school_id' => $schoolId,
            'teacher_duty_report_deadline_time' => '19:00:00',
            'teacher_duty_report_grace_minutes' => 120,
        ]);
    }

    private function grantPermission(
        User $user,
        string $permission
    ): void {
        $permissionId = DB::table('permissions')
            ->where('permission_name', $permission)
            ->value('id');

        $this->assertNotNull($permissionId);

        DB::table('role_permissions')->updateOrInsert([
            'role_id' => $user->role_id,
            'permission_id' => $permissionId,
        ]);
    }

    public function test_existing_tasks_consume_global_fifty_item_cap_before_teacher_duty_tasks(): void
    {
        CarbonImmutable::setTestNow(
            CarbonImmutable::parse('2026-09-09 12:00:00', 'Africa/Nairobi')
        );

        $school = SchoolBuilder::create();

        $role = RoleBuilder::create([
            'role_name' => 'Teacher',
        ]);

        $user = UserBuilder::create($school, $role);
        $teacher = TeacherBuilder::create($school, $user);

        $user = User::with('role')->findOrFail($user->id);

        $this->grantPermission(
            $user,
            'submit_teacher_duty_reports'
        );

        $this->teacherDutySettings((string) $school->id);

        $academicYear = AcademicYearBuilder::create($school, [
            'year_name' => '2026 Cap Test',
        ]);

        $term = TermBuilder::create($school, $academicYear, [
            'term_name' => 'Term 3 Cap Test',
            'start_date' => '2026-09-01',
            'end_date' => '2026-12-01',
        ]);

        $grade = GradeBuilder::create($school);
        $stream = StreamBuilder::create($school, $grade);
        $learningArea = LearningAreaBuilder::create();

        $teacherAssignmentId = (string) Str::uuid();

        DB::table('teacher_assignments')->insert([
            'id' => $teacherAssignmentId,
            'school_id' => $school->id,
            'teacher_id' => $teacher->id,
            'learning_area_id' => $learningArea->id,
            'grade_id' => $grade->id,
            'stream_id' => $stream->id,
            'academic_year_id' => $academicYear->id,
            'term_id' => $term->id,
            'lessons_per_week' => 5,
            'active' => true,
            'is_deleted' => false,
        ]);

        for ($i = 1; $i <= 35; $i++) {
            DB::table('teacher_workflows')->insert([
                'id' => (string) Str::uuid(),
                'school_id' => $school->id,
                'entity_type' => 'lesson_plan',
                'entity_id' => (string) Str::uuid(),
                'teacher_id' => $teacher->id,
                'state' => 'draft',
                'created_at' => now()->subMinutes($i),
                'updated_at' => now()->subMinutes($i),
            ]);
        }

        $attendanceSessionId = (string) Str::uuid();

        DB::table('attendance_sessions')->insert([
            'id' => $attendanceSessionId,
            'school_id' => $school->id,
            'session_name' => 'Cap Test Session',
            'active' => true,
        ]);

        for ($i = 1; $i <= 10; $i++) {
            DB::table('attendance_registers')->insert([
                'id' => (string) Str::uuid(),
                'school_id' => $school->id,
                'attendance_session_id' => $attendanceSessionId,
                'teacher_assignment_id' => $teacherAssignmentId,
                'teacher_id' => $teacher->id,
                'grade_id' => $grade->id,
                'stream_id' => $stream->id,
                'academic_year_id' => $academicYear->id,
                'term_id' => $term->id,
                'attendance_date' => '2026-09-09',
                'register_type' => 'lesson',
                'status' => 'draft',
                'opened_by' => $user->id,
                'opened_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $assessmentType = app(AssessmentTypeService::class)->create(
            ['assessment_type_name' => 'Cap Test Formative'],
            (string) $school->id
        );

        $exam = app(ExamService::class)->create(
            [
                'exam_name' => 'Cap Test Exam',
                'assessment_type_id' => $assessmentType->id,
                'academic_year_id' => $academicYear->id,
                'term_id' => $term->id,
                'start_date' => '2026-09-20',
                'end_date' => '2026-09-25',
            ],
            (string) $school->id,
            null
        );

        $examLearningArea = app(ExamLearningAreaService::class)->create(
            [
                'exam_id' => $exam->id,
                'learning_area_id' => $learningArea->id,
                'number_of_papers' => 5,
                'total_marks' => 100,
            ],
            (string) $school->id
        );

        for ($i = 1; $i <= 5; $i++) {
            $paper = app(ExamPaperService::class)->create(
                [
                    'exam_learning_area_id' => $examLearningArea->id,
                    'paper_name' => 'Cap Test Paper '.$i,
                    'paper_number' => $i,
                    'max_marks' => 20,
                ],
                (string) $school->id
            );

            DB::table('mark_entry_batches')->insert([
                'id' => (string) Str::uuid(),
                'school_id' => $school->id,
                'exam_id' => $exam->id,
                'exam_paper_id' => $paper->id,
                'teacher_assignment_id' => $teacherAssignmentId,
                'teacher_id' => $teacher->id,
                'entered_by' => $user->id,
                'status' => 'draft',
                'expected_learner_count' => 0,
                'entered_count' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $period = app(TeacherDutyRosterService::class)->createPeriod(
            (string) $school->id,
            '2026-09-07',
            '2026-09-11',
            null,
            (string) $user->id
        );

        app(TeacherDutyRosterService::class)->assignTeacher(
            (string) $school->id,
            (string) $period->id,
            (string) $teacher->id,
            (string) $user->id
        );

        $tasks = app(TeacherWorkflowService::class)->tasks($user);

        $this->assertCount(50, $tasks);

        $this->assertSame(
            35,
            $tasks->where('task_type', 'lesson_plan_due')->count()
        );

        $this->assertSame(
            10,
            $tasks->where('task_type', 'attendance_pending')->count()
        );

        $this->assertSame(
            5,
            $tasks->where('task_type', 'marks_entry_pending')->count()
        );

        $this->assertFalse(
            $tasks->contains(
                fn (array $task): bool => str_starts_with(
                    $task['task_type'],
                    'teacher_duty_'
                )
            )
        );

        $this->assertDatabaseCount('teacher_duty_daily_reports', 0);
        $this->assertDatabaseCount('teacher_duty_daily_report_history', 0);
    }

    public function test_teacher_duty_tasks_are_appended_after_existing_workflow_tasks(): void
    {
        CarbonImmutable::setTestNow(
            CarbonImmutable::parse('2026-09-09 12:00:00', 'Africa/Nairobi')
        );

        $school = SchoolBuilder::create();

        $role = RoleBuilder::create([
            'role_name' => 'Teacher',
        ]);

        $user = UserBuilder::create(
            $school,
            $role
        );

        $teacher = TeacherBuilder::create($school, $user);

        $submitPermissionId = DB::table('permissions')
            ->where('permission_name', 'submit_teacher_duty_reports')
            ->value('id');

        $this->assertNotNull($submitPermissionId);

        DB::table('role_permissions')->updateOrInsert(
            [
                'role_id' => $role->id,
                'permission_id' => $submitPermissionId,
            ],
            []
        );

        $this->teacherDutySettings((string) $school->id);

        $workflowEntityIds = [];

        for ($i = 0; $i < 35; $i++) {
            $entityId = (string) Str::uuid();
            $workflowEntityIds[] = $entityId;

            DB::table('teacher_workflows')->insert([
                'id' => (string) Str::uuid(),
                'school_id' => $school->id,
                'entity_type' => 'lesson_plan',
                'entity_id' => $entityId,
                'teacher_id' => $teacher->id,
                'teacher_assignment_id' => null,
                'state' => 'draft',
                'revision_number' => 1,
                'version' => 1,
                'created_at' => now()->subMinutes(35 - $i),
                'updated_at' => now()->subMinutes(35 - $i),
            ]);
        }

        $roster = app(TeacherDutyRosterService::class);

        $period = $roster->createPeriod(
            (string) $school->id,
            '2026-09-07',
            '2026-09-11',
            null,
            (string) $user->id
        );

        $roster->assignTeacher(
            (string) $school->id,
            (string) $period->id,
            (string) $teacher->id,
            (string) $user->id
        );

        $user = User::withoutGlobalScopes()->findOrFail($user->id);

        $tasks = app(TeacherWorkflowService::class)->tasks($user);

        $this->assertCount(36, $tasks);

        $this->assertSame(
            $workflowEntityIds[34],
            $tasks->first()['entity_reference']
        );

        $this->assertSame(
            $workflowEntityIds[0],
            $tasks[34]['entity_reference']
        );

        $this->assertSame(
            'teacher_duty_daily_report_due',
            $tasks[35]['task_type']
        );

        $this->assertSame(
            'teacher_duty_daily:'.$period->id.':2026-09-09',
            $tasks[35]['entity_reference']
        );
    }
}
