<?php

declare(strict_types=1);

namespace App\Services\TeacherDuty;

use App\Models\School;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TeacherDutyNotificationService
{
    public function __construct(
        private readonly TeacherDutyRosterService $roster,
        private readonly TeacherDutyDailyReportService $dailyReports,
        private readonly TeacherDutyAuthorizationService $authorization
    ) {}

    public function generate(): int
    {
        $inserted = 0;

        $schools = School::query()
            ->withoutGlobalScopes()
            ->get();

        foreach ($schools as $school) {
            $schoolId = (string) $school->id;

            foreach ($this->roster->currentPeriods($schoolId) as $period) {
                $today = CarbonImmutable::now(
                    $this->timezone($school)
                )->toDateString();

                if (
                    $today < $period->start_date->toDateString()
                    || $today > $period->end_date->toDateString()
                ) {
                    continue;
                }

                $assignments = $this->roster
                    ->currentAssignmentsForPeriod(
                        $schoolId,
                        (string) $period->id
                    );

                foreach ($assignments as $assignment) {
                    $teacher = $assignment->teacher()
                        ->withoutGlobalScopes()
                        ->first();

                    if (! $teacher || ! $teacher->user_id) {
                        continue;
                    }

                    $userId = (string) $teacher->user_id;

                    try {
                        $state = $this->dailyReports->state(
                            $schoolId,
                            (string) $period->id,
                            $today,
                            $userId
                        );
                    } catch (ValidationException) {
                        continue;
                    }

                    if (($state['state'] ?? null) !== 'OVERDUE') {
                        continue;
                    }

                    $inserted += DB::table('notifications')
                        ->insertOrIgnore([
                            'school_id' => $schoolId,
                            'user_id' => $userId,
                            'title' => 'Teacher duty daily report overdue',
                            'message' => 'Your teacher duty daily report is overdue.',
                            'notification_type' => 'teacher_duty_daily_overdue',
                            'notification_key' => 'teacher_duty:daily:'
                                .$period->id.':'.$today
                                .':reporter_overdue',
                            'state' => 'unread',
                            'is_read' => false,
                            'action_url' => '/teacher/teacher-duty/daily-reports',
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);

                    $inserted += $this->notifyReviewers(
                        $schoolId,
                        (string) $period->id,
                        $today
                    );
                }
            }
        }

        $inserted += $this->notifyWeeklyDraftReporters();
        $inserted += $this->notifyWeeklyChangesRequestedReporters();
        $inserted += $this->notifyWeeklySubmittedReviewers();

        return $inserted;
    }

    private function notifyWeeklySubmittedReviewers(): int
    {
        $inserted = 0;

        $reports = DB::table('teacher_duty_weekly_reports')
            ->where('status', 'submitted')
            ->get([
                'id',
                'school_id',
            ]);

        foreach ($reports as $report) {
            $history = DB::table(
                'teacher_duty_weekly_report_history'
            )
                ->where('school_id', $report->school_id)
                ->where('weekly_report_id', $report->id)
                ->where('to_status', 'submitted')
                ->whereIn('event', [
                    'submitted',
                    'resubmitted',
                ])
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->first();

            if (! $history) {
                continue;
            }

            $users = User::query()
                ->withoutGlobalScopes()
                ->where(
                    'school_id',
                    $report->school_id
                )
                ->get();

            foreach ($users as $user) {
                try {
                    $reviewer = $this->authorization->reviewer(
                        (string) $report->school_id,
                        (string) $user->id
                    );
                } catch (ValidationException) {
                    continue;
                }

                $inserted += DB::table('notifications')
                    ->insertOrIgnore([
                        'school_id' => (string) $report->school_id,
                        'user_id' => (string) $reviewer->id,
                        'title' => 'Teacher duty weekly report submitted',
                        'message' => 'A teacher duty weekly report is ready for review.',
                        'notification_type' => 'teacher_duty_weekly_submitted',
                        'notification_key' => 'teacher_duty:weekly:'
                            .$report->id
                            .':history:'.$history->id
                            .':reviewer_submitted',
                        'state' => 'unread',
                        'is_read' => false,
                        'action_url' => '/teacher-duty',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
            }
        }

        return $inserted;
    }

    private function notifyWeeklyChangesRequestedReporters(): int
    {
        $inserted = 0;

        $reports = DB::table('teacher_duty_weekly_reports')
            ->where('status', 'changes_requested')
            ->get([
                'id',
                'school_id',
                'duty_period_id',
            ]);

        foreach ($reports as $report) {
            $history = DB::table(
                'teacher_duty_weekly_report_history'
            )
                ->where('school_id', $report->school_id)
                ->where('weekly_report_id', $report->id)
                ->where('event', 'changes_requested')
                ->where('to_status', 'changes_requested')
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->first();

            if (! $history) {
                continue;
            }

            $assignments = DB::table('teacher_duty_assignments')
                ->where('school_id', $report->school_id)
                ->where(
                    'duty_period_id',
                    $report->duty_period_id
                )
                ->orderBy('created_at')
                ->orderBy('id')
                ->get();

            foreach ($assignments as $assignment) {
                $teacher = DB::table('teachers')
                    ->where('id', $assignment->teacher_id)
                    ->where('school_id', $report->school_id)
                    ->first();

                if (! $teacher || ! $teacher->user_id) {
                    continue;
                }

                try {
                    $reporter = $this->authorization->reporter(
                        (string) $report->school_id,
                        (string) $report->duty_period_id,
                        (string) $teacher->user_id
                    );
                } catch (ValidationException) {
                    continue;
                }

                $inserted += DB::table('notifications')
                    ->insertOrIgnore([
                        'school_id' => (string) $report->school_id,
                        'user_id' => (string) $reporter->id,
                        'title' => 'Teacher duty weekly report changes requested',
                        'message' => 'Changes were requested for your teacher duty weekly report.',
                        'notification_type' => 'teacher_duty_weekly_changes_requested',
                        'notification_key' => 'teacher_duty:weekly:'
                            .$report->id
                            .':history:'.$history->id
                            .':changes_requested',
                        'state' => 'unread',
                        'is_read' => false,
                        'action_url' => '/teacher/teacher-duty/weekly-reports/'
                            .$report->id,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
            }
        }

        return $inserted;
    }

    private function notifyWeeklyDraftReporters(): int
    {
        $inserted = 0;

        $reports = DB::table('teacher_duty_weekly_reports as reports')
            ->join('teacher_duty_periods as periods', function ($join) {
                $join->on('periods.id', '=', 'reports.duty_period_id')
                    ->on('periods.school_id', '=', 'reports.school_id');
            })
            ->where('reports.status', 'draft')
            ->where('periods.active', false)
            ->whereNotNull('periods.ended_by')
            ->whereNotNull('periods.ended_at')
            ->select([
                'reports.id',
                'reports.school_id',
                'reports.duty_period_id',
            ])
            ->get();

        foreach ($reports as $report) {
            $assignments = DB::table('teacher_duty_assignments')
                ->where('school_id', $report->school_id)
                ->where(
                    'duty_period_id',
                    $report->duty_period_id
                )
                ->orderBy('created_at')
                ->orderBy('id')
                ->get();

            foreach ($assignments as $assignment) {
                $teacher = DB::table('teachers')
                    ->where('id', $assignment->teacher_id)
                    ->where('school_id', $report->school_id)
                    ->first();

                if (! $teacher || ! $teacher->user_id) {
                    continue;
                }

                $userId = (string) $teacher->user_id;

                try {
                    $reporter = $this->authorization->reporter(
                        (string) $report->school_id,
                        (string) $report->duty_period_id,
                        $userId
                    );
                } catch (ValidationException) {
                    continue;
                }

                $inserted += DB::table('notifications')
                    ->insertOrIgnore([
                        'school_id' => (string) $report->school_id,
                        'user_id' => (string) $reporter->id,
                        'title' => 'Teacher duty weekly report due',
                        'message' => 'Your teacher duty weekly report is ready for completion.',
                        'notification_type' => 'teacher_duty_weekly_report_due',
                        'notification_key' => 'teacher_duty:weekly:'
                            .$report->id.':reporter_due',
                        'state' => 'unread',
                        'is_read' => false,
                        'action_url' => '/teacher/teacher-duty/weekly-reports/'
                            .$report->id,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
            }
        }

        return $inserted;
    }

    private function notifyReviewers(
        string $schoolId,
        string $periodId,
        string $reportDate
    ): int {
        $inserted = 0;

        $users = User::query()
            ->withoutGlobalScopes()
            ->where('school_id', $schoolId)
            ->get();

        foreach ($users as $user) {
            try {
                $reviewer = $this->authorization->reviewer(
                    $schoolId,
                    (string) $user->id
                );
            } catch (ValidationException) {
                continue;
            }

            $inserted += DB::table('notifications')
                ->insertOrIgnore([
                    'school_id' => $schoolId,
                    'user_id' => (string) $reviewer->id,
                    'title' => 'Teacher duty report overdue',
                    'message' => 'A teacher duty daily report is overdue.',
                    'notification_type' => 'teacher_duty_daily_overdue_escalation',
                    'notification_key' => 'teacher_duty:daily:'
                        .$periodId.':'.$reportDate
                        .':reviewer_escalation',
                    'state' => 'unread',
                    'is_read' => false,
                    'action_url' => '/teacher-duty',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
        }

        return $inserted;
    }

    private function timezone(School $school): string
    {
        return $school->timezone ?: config('app.timezone', 'UTC');
    }
}
