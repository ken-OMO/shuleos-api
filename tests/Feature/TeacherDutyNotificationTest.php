<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\TeacherDuty\TeacherDutyNotificationService;
use App\Services\TeacherDuty\TeacherDutyRosterService;
use App\Services\TeacherDuty\TeacherDutyWeeklyReportService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\Support\Database\RoleBuilder;
use Tests\Support\Database\SchoolBuilder;
use Tests\Support\Database\TeacherBuilder;
use Tests\Support\Database\UserBuilder;
use Tests\TestCase;

class TeacherDutyNotificationTest extends TestCase
{
    use DatabaseTransactions;

    public function test_overdue_daily_report_notifies_assigned_reporter_without_creating_report(): void
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

            $user = UserBuilder::create($school, $role);

            $teacher = TeacherBuilder::create(
                $school,
                $user
            );

            $user = User::with('role')->findOrFail($user->id);

            $this->grantPermission(
                $user,
                'submit_teacher_duty_reports'
            );

            $this->teacherDutySettings((string) $school->id);

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

            $reportsBefore = DB::table(
                'teacher_duty_daily_reports'
            )->count();

            $historyBefore = DB::table(
                'teacher_duty_daily_report_history'
            )->count();

            $count = app(TeacherDutyNotificationService::class)
                ->generate();

            $this->assertSame(1, $count);

            $this->assertDatabaseHas('notifications', [
                'school_id' => $school->id,
                'user_id' => $user->id,
                'notification_type' => 'teacher_duty_daily_overdue',
                'notification_key' => 'teacher_duty:daily:'.$period->id
                    .':2026-09-09:reporter_overdue',
                'state' => 'unread',
                'is_read' => false,
            ]);

            $secondCount = app(TeacherDutyNotificationService::class)
                ->generate();

            $this->assertSame(0, $secondCount);

            $this->assertSame(
                1,
                DB::table('notifications')
                    ->where('school_id', $school->id)
                    ->where('user_id', $user->id)
                    ->where(
                        'notification_key',
                        'teacher_duty:daily:'.$period->id
                            .':2026-09-09:reporter_overdue'
                    )
                    ->count()
            );

            $this->assertSame(
                $reportsBefore,
                DB::table('teacher_duty_daily_reports')->count()
            );

            $this->assertSame(
                $historyBefore,
                DB::table('teacher_duty_daily_report_history')->count()
            );
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_overdue_daily_report_escalates_to_eligible_same_school_reviewer(): void
    {
        CarbonImmutable::setTestNow(
            CarbonImmutable::parse(
                '2026-09-09 21:00:01',
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

            $reportsBefore = DB::table(
                'teacher_duty_daily_reports'
            )->count();

            $historyBefore = DB::table(
                'teacher_duty_daily_report_history'
            )->count();

            $count = app(TeacherDutyNotificationService::class)
                ->generate();

            $this->assertSame(2, $count);

            $this->assertDatabaseHas('notifications', [
                'school_id' => $school->id,
                'user_id' => $reporter->id,
                'notification_type' => 'teacher_duty_daily_overdue',
                'notification_key' => 'teacher_duty:daily:'.$period->id
                    .':2026-09-09:reporter_overdue',
            ]);

            $this->assertDatabaseHas('notifications', [
                'school_id' => $school->id,
                'user_id' => $reviewer->id,
                'notification_type' => 'teacher_duty_daily_overdue_escalation',
                'notification_key' => 'teacher_duty:daily:'.$period->id
                    .':2026-09-09:reviewer_escalation',
                'state' => 'unread',
                'is_read' => false,
            ]);

            $this->assertSame(
                $reportsBefore,
                DB::table('teacher_duty_daily_reports')->count()
            );

            $this->assertSame(
                $historyBefore,
                DB::table('teacher_duty_daily_report_history')->count()
            );
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_daily_overdue_escalation_excludes_ineligible_and_cross_school_reviewers(): void
    {
        CarbonImmutable::setTestNow(
            CarbonImmutable::parse(
                '2026-09-09 21:00:01',
                'Africa/Nairobi'
            )
        );

        try {
            $school = SchoolBuilder::create();
            $otherSchool = SchoolBuilder::create();

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

            $eligible = UserBuilder::create(
                $school,
                $reviewerRole
            );

            $inactive = UserBuilder::create(
                $school,
                $reviewerRole,
                ['active' => false]
            );

            $deleted = UserBuilder::create(
                $school,
                $reviewerRole,
                ['is_deleted' => true]
            );

            $suspended = UserBuilder::create(
                $school,
                $reviewerRole,
                ['suspended_at' => now()]
            );

            $permissionlessRole = RoleBuilder::create([
                'role_name' => 'No Duty Review',
            ]);

            $permissionless = UserBuilder::create(
                $school,
                $permissionlessRole
            );

            $otherReviewer = UserBuilder::create(
                $otherSchool,
                $reviewerRole
            );

            foreach ([
                $eligible,
                $inactive,
                $deleted,
                $suspended,
                $otherReviewer,
            ] as $candidate) {
                $candidate = User::with('role')->findOrFail(
                    $candidate->id
                );

                $this->grantPermission(
                    $candidate,
                    'review_teacher_duty_reports'
                );
            }

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

            $count = app(TeacherDutyNotificationService::class)
                ->generate();

            $this->assertSame(2, $count);

            $key = 'teacher_duty:daily:'.$period->id
                .':2026-09-09:reviewer_escalation';

            $this->assertDatabaseHas('notifications', [
                'school_id' => $school->id,
                'user_id' => $eligible->id,
                'notification_key' => $key,
            ]);

            foreach ([
                $inactive,
                $deleted,
                $suspended,
                $permissionless,
                $otherReviewer,
            ] as $excluded) {
                $this->assertDatabaseMissing('notifications', [
                    'user_id' => $excluded->id,
                    'notification_key' => $key,
                ]);
            }
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_ended_period_draft_weekly_report_notifies_historical_reporter_without_mutating_lifecycle(): void
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

            $reporter = UserBuilder::create(
                $school,
                $role
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

            $report = app(
                TeacherDutyWeeklyReportService::class
            )->openReport(
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

            $reportsBefore = DB::table(
                'teacher_duty_weekly_reports'
            )->count();

            $historyBefore = DB::table(
                'teacher_duty_weekly_report_history'
            )->count();

            $count = app(TeacherDutyNotificationService::class)
                ->generate();

            $this->assertSame(1, $count);

            $this->assertDatabaseHas('notifications', [
                'school_id' => $school->id,
                'user_id' => $reporter->id,
                'notification_type' => 'teacher_duty_weekly_report_due',
                'notification_key' => 'teacher_duty:weekly:'
                    .$report->id.':reporter_due',
                'state' => 'unread',
                'is_read' => false,
            ]);

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

            $secondCount = app(
                TeacherDutyNotificationService::class
            )->generate();

            $this->assertSame(0, $secondCount);

            $this->assertSame(
                1,
                DB::table('notifications')
                    ->where('school_id', $school->id)
                    ->where('user_id', $reporter->id)
                    ->where(
                        'notification_key',
                        'teacher_duty:weekly:'
                            .$report->id.':reporter_due'
                    )
                    ->count()
            );
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_weekly_changes_requested_notifies_reporter_using_history_event_identity(): void
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

            $history = DB::table(
                'teacher_duty_weekly_report_history'
            )
                ->where('school_id', $school->id)
                ->where('weekly_report_id', $report->id)
                ->where('event', 'changes_requested')
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->first();

            $this->assertNotNull($history);

            $reportsBefore = DB::table(
                'teacher_duty_weekly_reports'
            )->count();

            $historyBefore = DB::table(
                'teacher_duty_weekly_report_history'
            )->count();

            $count = app(
                TeacherDutyNotificationService::class
            )->generate();

            $this->assertSame(1, $count);

            $key = 'teacher_duty:weekly:'.$report->id
                .':history:'.$history->id
                .':changes_requested';

            $this->assertDatabaseHas('notifications', [
                'school_id' => $school->id,
                'user_id' => $reporter->id,
                'notification_type' => 'teacher_duty_weekly_changes_requested',
                'notification_key' => $key,
                'state' => 'unread',
                'is_read' => false,
            ]);

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

            $secondCount = app(
                TeacherDutyNotificationService::class
            )->generate();

            $this->assertSame(0, $secondCount);

            $this->assertSame(
                1,
                DB::table('notifications')
                    ->where('school_id', $school->id)
                    ->where('user_id', $reporter->id)
                    ->where('notification_key', $key)
                    ->count()
            );
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_submitted_weekly_report_notifies_eligible_reviewer_using_history_event_identity(): void
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

            $history = DB::table(
                'teacher_duty_weekly_report_history'
            )
                ->where('school_id', $school->id)
                ->where('weekly_report_id', $report->id)
                ->where('event', 'submitted')
                ->where('to_status', 'submitted')
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->first();

            $this->assertNotNull($history);

            $reportsBefore = DB::table(
                'teacher_duty_weekly_reports'
            )->count();

            $historyBefore = DB::table(
                'teacher_duty_weekly_report_history'
            )->count();

            $count = app(
                TeacherDutyNotificationService::class
            )->generate();

            $this->assertSame(1, $count);

            $key = 'teacher_duty:weekly:'.$report->id
                .':history:'.$history->id
                .':reviewer_submitted';

            $this->assertDatabaseHas('notifications', [
                'school_id' => $school->id,
                'user_id' => $reviewer->id,
                'notification_type' => 'teacher_duty_weekly_submitted',
                'notification_key' => $key,
                'state' => 'unread',
                'is_read' => false,
            ]);

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

            $secondCount = app(
                TeacherDutyNotificationService::class
            )->generate();

            $this->assertSame(0, $secondCount);

            $this->assertSame(
                1,
                DB::table('notifications')
                    ->where('school_id', $school->id)
                    ->where('user_id', $reviewer->id)
                    ->where('notification_key', $key)
                    ->count()
            );
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_weekly_resubmission_creates_fresh_reviewer_notification_for_new_history_event(): void
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

            $submittedHistory = DB::table(
                'teacher_duty_weekly_report_history'
            )
                ->where('school_id', $school->id)
                ->where('weekly_report_id', $report->id)
                ->where('event', 'submitted')
                ->where('to_status', 'submitted')
                ->first();

            $this->assertNotNull($submittedHistory);

            $notifications = app(
                TeacherDutyNotificationService::class
            );

            $this->assertSame(
                1,
                $notifications->generate()
            );

            $submittedKey = 'teacher_duty:weekly:'
                .$report->id
                .':history:'.$submittedHistory->id
                .':reviewer_submitted';

            $this->assertDatabaseHas('notifications', [
                'school_id' => $school->id,
                'user_id' => $reviewer->id,
                'notification_key' => $submittedKey,
            ]);

            $weekly->reviewReport(
                (string) $school->id,
                (string) $report->id,
                'changes_requested',
                'Please clarify the reported challenges.',
                (string) $reviewer->id
            );

            $this->assertSame(
                1,
                $notifications->generate()
            );

            $weekly->resubmitReport(
                (string) $school->id,
                (string) $report->id,
                (string) $reporter->id
            );

            $resubmittedHistory = DB::table(
                'teacher_duty_weekly_report_history'
            )
                ->where('school_id', $school->id)
                ->where('weekly_report_id', $report->id)
                ->where('event', 'resubmitted')
                ->where('to_status', 'submitted')
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->first();

            $this->assertNotNull($resubmittedHistory);

            $this->assertNotSame(
                (string) $submittedHistory->id,
                (string) $resubmittedHistory->id
            );

            $this->assertSame(
                1,
                $notifications->generate()
            );

            $resubmittedKey = 'teacher_duty:weekly:'
                .$report->id
                .':history:'.$resubmittedHistory->id
                .':reviewer_submitted';

            $this->assertDatabaseHas('notifications', [
                'school_id' => $school->id,
                'user_id' => $reviewer->id,
                'notification_type' => 'teacher_duty_weekly_submitted',
                'notification_key' => $resubmittedKey,
            ]);

            $this->assertDatabaseHas('notifications', [
                'school_id' => $school->id,
                'user_id' => $reviewer->id,
                'notification_key' => $submittedKey,
            ]);

            $this->assertSame(
                2,
                DB::table('notifications')
                    ->where('school_id', $school->id)
                    ->where('user_id', $reviewer->id)
                    ->where(
                        'notification_type',
                        'teacher_duty_weekly_submitted'
                    )
                    ->whereIn('notification_key', [
                        $submittedKey,
                        $resubmittedKey,
                    ])
                    ->count()
            );

            $this->assertSame(
                0,
                $notifications->generate()
            );
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_weekly_submitted_notification_excludes_ineligible_and_cross_school_reviewers(): void
    {
        CarbonImmutable::setTestNow(
            CarbonImmutable::parse(
                '2026-09-12 08:00:00',
                'Africa/Nairobi'
            )
        );

        try {
            $school = SchoolBuilder::create();
            $otherSchool = SchoolBuilder::create();

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

            $eligible = UserBuilder::create(
                $school,
                $reviewerRole
            );

            $inactive = UserBuilder::create(
                $school,
                $reviewerRole,
                ['active' => false]
            );

            $deleted = UserBuilder::create(
                $school,
                $reviewerRole,
                ['is_deleted' => true]
            );

            $suspended = UserBuilder::create(
                $school,
                $reviewerRole,
                ['suspended_at' => now()]
            );

            $permissionlessRole = RoleBuilder::create([
                'role_name' => 'No Review Permission',
            ]);

            $permissionless = UserBuilder::create(
                $school,
                $permissionlessRole
            );

            $otherReviewer = UserBuilder::create(
                $otherSchool,
                $reviewerRole
            );

            foreach ([
                $eligible,
                $inactive,
                $deleted,
                $suspended,
                $otherReviewer,
            ] as $candidate) {
                $candidate = User::with('role')->findOrFail(
                    $candidate->id
                );

                $this->grantPermission(
                    $candidate,
                    'review_teacher_duty_reports'
                );
            }

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

            $history = DB::table(
                'teacher_duty_weekly_report_history'
            )
                ->where('school_id', $school->id)
                ->where('weekly_report_id', $report->id)
                ->where('event', 'submitted')
                ->where('to_status', 'submitted')
                ->first();

            $this->assertNotNull($history);

            $count = app(
                TeacherDutyNotificationService::class
            )->generate();

            $this->assertSame(1, $count);

            $key = 'teacher_duty:weekly:'.$report->id
                .':history:'.$history->id
                .':reviewer_submitted';

            $this->assertDatabaseHas('notifications', [
                'school_id' => $school->id,
                'user_id' => $eligible->id,
                'notification_key' => $key,
            ]);

            foreach ([
                $inactive,
                $deleted,
                $suspended,
                $permissionless,
                $otherReviewer,
            ] as $excluded) {
                $this->assertDatabaseMissing('notifications', [
                    'user_id' => $excluded->id,
                    'notification_key' => $key,
                ]);
            }
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_weekly_changes_requested_notifies_all_eligible_historical_reporters(): void
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

            $reporterOne = UserBuilder::create(
                $school,
                $teacherRole
            );

            $reporterTwo = UserBuilder::create(
                $school,
                $teacherRole
            );

            $teacherOne = TeacherBuilder::create(
                $school,
                $reporterOne
            );

            $teacherTwo = TeacherBuilder::create(
                $school,
                $reporterTwo
            );

            $reporterOne = User::with('role')->findOrFail(
                $reporterOne->id
            );

            $reporterTwo = User::with('role')->findOrFail(
                $reporterTwo->id
            );

            $this->grantPermission(
                $reporterOne,
                'submit_teacher_duty_reports'
            );

            $this->grantPermission(
                $reporterTwo,
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
                (string) $reporterOne->id
            );

            $roster->assignTeacher(
                (string) $school->id,
                (string) $period->id,
                (string) $teacherOne->id,
                (string) $reporterOne->id
            );

            $roster->assignTeacher(
                (string) $school->id,
                (string) $period->id,
                (string) $teacherTwo->id,
                (string) $reporterOne->id
            );

            $weekly = app(
                TeacherDutyWeeklyReportService::class
            );

            $report = $weekly->openReport(
                (string) $school->id,
                (string) $period->id,
                (string) $reporterOne->id
            );

            $roster->endPeriod(
                (string) $school->id,
                (string) $period->id,
                (string) $reporterOne->id,
                'Duty week completed'
            );

            $weekly->submitReport(
                (string) $school->id,
                (string) $report->id,
                (string) $reporterOne->id
            );

            $weekly->reviewReport(
                (string) $school->id,
                (string) $report->id,
                'changes_requested',
                'Please clarify the reported challenges.',
                (string) $reviewer->id
            );

            $history = DB::table(
                'teacher_duty_weekly_report_history'
            )
                ->where('school_id', $school->id)
                ->where('weekly_report_id', $report->id)
                ->where('event', 'changes_requested')
                ->where('to_status', 'changes_requested')
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->first();

            $this->assertNotNull($history);

            $count = app(
                TeacherDutyNotificationService::class
            )->generate();

            $this->assertSame(2, $count);

            $key = 'teacher_duty:weekly:'.$report->id
                .':history:'.$history->id
                .':changes_requested';

            $this->assertDatabaseHas('notifications', [
                'school_id' => $school->id,
                'user_id' => $reporterOne->id,
                'notification_key' => $key,
            ]);

            $this->assertDatabaseHas('notifications', [
                'school_id' => $school->id,
                'user_id' => $reporterTwo->id,
                'notification_key' => $key,
            ]);

            $this->assertSame(
                2,
                DB::table('notifications')
                    ->where('school_id', $school->id)
                    ->where('notification_key', $key)
                    ->count()
            );
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_weekly_changes_requested_excludes_ineligible_historical_reporters(): void
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

            $eligible = UserBuilder::create(
                $school,
                $teacherRole
            );

            $inactive = UserBuilder::create(
                $school,
                $teacherRole
            );

            $deleted = UserBuilder::create(
                $school,
                $teacherRole
            );

            $suspended = UserBuilder::create(
                $school,
                $teacherRole
            );

            $permissionlessRole = RoleBuilder::create([
                'role_name' => 'No Submit Permission',
            ]);

            $permissionless = UserBuilder::create(
                $school,
                $permissionlessRole
            );

            $teacherEligible = TeacherBuilder::create(
                $school,
                $eligible
            );

            $teacherInactive = TeacherBuilder::create(
                $school,
                $inactive
            );

            $teacherDeleted = TeacherBuilder::create(
                $school,
                $deleted
            );

            $teacherSuspended = TeacherBuilder::create(
                $school,
                $suspended
            );

            $teacherPermissionless = TeacherBuilder::create(
                $school,
                $permissionless
            );

            foreach ([
                $eligible,
                $inactive,
                $deleted,
                $suspended,
            ] as $candidate) {
                $candidate = User::with('role')->findOrFail(
                    $candidate->id
                );

                $this->grantPermission(
                    $candidate,
                    'submit_teacher_duty_reports'
                );
            }

            $eligible = User::with('role')->findOrFail(
                $eligible->id
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
                (string) $eligible->id
            );

            foreach ([
                $teacherEligible,
                $teacherInactive,
                $teacherDeleted,
                $teacherSuspended,
                $teacherPermissionless,
            ] as $teacher) {
                $roster->assignTeacher(
                    (string) $school->id,
                    (string) $period->id,
                    (string) $teacher->id,
                    (string) $eligible->id
                );
            }

            $weekly = app(
                TeacherDutyWeeklyReportService::class
            );

            $report = $weekly->openReport(
                (string) $school->id,
                (string) $period->id,
                (string) $eligible->id
            );

            $roster->endPeriod(
                (string) $school->id,
                (string) $period->id,
                (string) $eligible->id,
                'Duty week completed'
            );

            $weekly->submitReport(
                (string) $school->id,
                (string) $report->id,
                (string) $eligible->id
            );

            $weekly->reviewReport(
                (string) $school->id,
                (string) $report->id,
                'changes_requested',
                'Please clarify the reported challenges.',
                (string) $reviewer->id
            );

            DB::table('users')
                ->where('id', $inactive->id)
                ->update(['active' => false]);

            DB::table('users')
                ->where('id', $deleted->id)
                ->update(['is_deleted' => true]);

            DB::table('users')
                ->where('id', $suspended->id)
                ->update(['suspended_at' => now()]);

            $history = DB::table(
                'teacher_duty_weekly_report_history'
            )
                ->where('school_id', $school->id)
                ->where('weekly_report_id', $report->id)
                ->where('event', 'changes_requested')
                ->where('to_status', 'changes_requested')
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->first();

            $this->assertNotNull($history);

            $count = app(
                TeacherDutyNotificationService::class
            )->generate();

            $this->assertSame(1, $count);

            $key = 'teacher_duty:weekly:'.$report->id
                .':history:'.$history->id
                .':changes_requested';

            $this->assertDatabaseHas('notifications', [
                'school_id' => $school->id,
                'user_id' => $eligible->id,
                'notification_key' => $key,
            ]);

            foreach ([
                $inactive,
                $deleted,
                $suspended,
                $permissionless,
            ] as $excluded) {
                $this->assertDatabaseMissing('notifications', [
                    'user_id' => $excluded->id,
                    'notification_key' => $key,
                ]);
            }
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_teacher_duty_notification_command_invokes_generator_successfully(): void
    {
        $this->artisan('teacher-duty:send-notifications')
            ->expectsOutput(
                'Created 0 teacher duty notifications.'
            )
            ->assertSuccessful();
    }

    private function grantPermission(User $user, string $permissionName): void
    {
        $permissionId = DB::table('permissions')
            ->where('permission_name', $permissionName)
            ->value('id');

        $this->assertNotNull(
            $permissionId,
            "Missing permission [{$permissionName}]."
        );

        DB::table('role_permissions')->insertOrIgnore([
            'role_id' => $user->role_id,
            'permission_id' => $permissionId,
        ]);

        $user->load('role');
    }

    private function teacherDutySettings(string $schoolId): void
    {
        DB::table('school_settings')->insert([
            'school_id' => $schoolId,
            'teacher_duty_report_deadline_time' => '19:00:00',
            'teacher_duty_report_grace_minutes' => 120,
        ]);
    }
}
