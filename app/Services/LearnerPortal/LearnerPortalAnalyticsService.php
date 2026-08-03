<?php

namespace App\Services\LearnerPortal;

use App\Models\User;
use App\Services\Attendance\AttendanceReadService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class LearnerPortalAnalyticsService
{
    public function __construct(private LearnerPortalAccessService $access, private LearnerTaskService $tasks, private LearnerAcademicProgressService $progress, private AttendanceReadService $attendance) {}

    public function summary(User $user): array
    {
        $learner = $this->access->learner($user);
        $homework = DB::table('homework_assignment_learners as assigned')->join('homework_assignments as homework', 'homework.id', '=', 'assigned.assignment_id')->where('assigned.school_id', $user->school_id)->where('assigned.learner_id', $learner->id)->where('homework.is_deleted', false);
        $assigned = (clone $homework)->count();
        $completedStates = ['submitted', 'late', 'resubmitted', 'reviewed', 'released'];
        $completed = (clone $homework)->whereIn('assigned.submission_status', $completedStates)->count();
        $overdue = (clone $homework)->where('homework.due_at', '<', now())->whereNotIn('assigned.submission_status', $completedStates)->count();
        $submissions = DB::table('homework_submissions')->where('school_id', $user->school_id)->where('learner_id', $learner->id)->whereNotNull('submitted_at')->latest('submitted_at')->limit(100)->get(['created_at', 'submitted_at']);
        $turnaround = $submissions->map(fn ($submission) => max(0, Carbon::parse($submission->created_at)->diffInMinutes(Carbon::parse($submission->submitted_at))))->avg();
        $progress = $this->progress->summary($user);

        return [
            'homework' => ['assigned' => $assigned, 'completed' => $completed, 'overdue' => $overdue, 'completion_rate' => $assigned ? round($completed / $assigned * 100, 2) : null],
            'submission_turnaround_minutes' => $turnaround === null ? null : round((float) $turnaround, 2),
            'feedback_available' => DB::table('homework_submission_marks as marks')->join('homework_submissions as submissions', 'submissions.id', '=', 'marks.submission_id')->where('submissions.school_id', $user->school_id)->where('submissions.learner_id', $learner->id)->where('marks.status', 'released')->count(),
            'attendance' => $this->attendance->summary($this->attendance->learner($user)->whereDate('attendance_date', '>=', today()->subDays(365))),
            'result_periods_available' => $progress['published_periods'],
            'learning_area_trends' => $progress['learning_areas'],
            'resource_usage' => DB::table('learning_resource_access_logs')->where('school_id', $user->school_id)->where('learner_id', $learner->id)->where('occurred_at', '>=', now()->subYear())->count(),
            'unread_notifications' => DB::table('notifications')->where('school_id', $user->school_id)->where('user_id', $user->id)->where('is_read', false)->count(),
            'pending_tasks' => $this->tasks->tasks($user)->count(),
            'sync_conflicts' => DB::table('learner_sync_conflicts')->where('school_id', $user->school_id)->where('user_id', $user->id)->where('status', 'open')->count(),
            'active_devices' => DB::table('learner_portal_devices')->where('school_id', $user->school_id)->where('user_id', $user->id)->whereNull('revoked_at')->count(),
            'ranking_included' => false,
            'ai_score' => null,
        ];
    }
}
