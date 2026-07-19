<?php

namespace App\Services\LearnerPortal;

use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class LearnerTaskService
{
    private const LIMIT = 60;

    public function __construct(private LearnerPortalAccessService $access) {}

    public function tasks(User $user): Collection
    {
        $learner = $this->access->learner($user);
        $tasks = collect();

        DB::table('homework_assignment_learners as assigned')
            ->join('homework_assignments as homework', 'homework.id', '=', 'assigned.assignment_id')
            ->where('assigned.school_id', $user->school_id)->where('assigned.learner_id', $learner->id)
            ->where('assigned.availability_status', 'available')->where('homework.status', 'published')->where('homework.is_deleted', false)
            ->orderBy('homework.due_at')->limit(40)
            ->get(['homework.id', 'homework.title', 'homework.due_at', 'homework.learning_area_id', 'assigned.submission_status'])
            ->each(function ($homework) use ($tasks) {
                if (in_array($homework->submission_status, ['submitted', 'late', 'resubmitted', 'reviewed', 'released'], true)) {
                    return;
                }
                $type = $homework->submission_status === 'in_progress' ? 'submission_draft' : (now()->gt($homework->due_at) ? 'homework_overdue' : 'homework_due');
                $this->add($tasks, $type, $homework->title, $type === 'homework_overdue' ? 'high' : 'normal', $homework->due_at, $homework->submission_status, $homework->id, '/learner/homework/'.$homework->id, $homework->learning_area_id);
            });

        DB::table('homework_submissions as submissions')
            ->leftJoin('homework_submission_marks as marks', 'marks.submission_id', '=', 'submissions.id')
            ->where('submissions.school_id', $user->school_id)->where('submissions.learner_id', $learner->id)
            ->where(fn ($query) => $query->whereIn('submissions.submission_status', ['returned', 'resubmission_required'])->orWhere('marks.status', 'released'))
            ->latest('submissions.updated_at')->limit(20)
            ->get(['submissions.assignment_id', 'submissions.submission_status', 'marks.status as mark_status'])
            ->each(function ($submission) use ($tasks) {
                $released = $submission->mark_status === 'released';
                $this->add($tasks, $released ? 'feedback_available' : 'submission_returned', $released ? 'Homework feedback available' : 'Homework submission returned', $released ? 'normal' : 'high', null, $released ? 'released' : $submission->submission_status, $submission->assignment_id, '/learner/homework/'.$submission->assignment_id);
            });

        DB::table('learning_resources')->where('school_id', $user->school_id)->where('publication_status', 'published')->where('is_deleted', false)
            ->where('grade_id', $learner->grade_id)->where(fn ($query) => $query->whereNull('stream_id')->orWhere('stream_id', $learner->stream_id))
            ->where('published_at', '>=', now()->subDays(14))->latest('published_at')->limit(8)->get(['id', 'title', 'learning_area_id'])
            ->each(fn ($resource) => $this->add($tasks, 'resource_available', $resource->title, 'normal', null, 'available', $resource->id, '/learner/learning-resources/'.$resource->id, $resource->learning_area_id));

        DB::table('report_cards')->where('school_id', $user->school_id)->where('learner_id', $learner->id)->where('status', 'published')->where('is_deleted', false)->where('published_at', '>=', now()->subDays(30))->latest('published_at')->limit(5)->get(['id', 'published_at'])
            ->each(fn ($card) => $this->add($tasks, 'report_card_available', 'Report card available', 'normal', null, 'published', $card->id, '/learner/report-cards/'.$card->id));

        DB::table('learning_area_results as results')->join('exams', 'exams.id', '=', 'results.exam_id')->where('results.school_id', $user->school_id)->where('results.learner_id', $learner->id)->where('results.processing_status', 'processed')->where('results.is_deleted', false)->where('exams.status', 'published')->where('results.processed_at', '>=', now()->subDays(30))->distinct()->limit(5)->get(['results.exam_id'])
            ->each(fn ($result) => $this->add($tasks, 'result_published', 'Results published', 'normal', null, 'published', $result->exam_id, '/learner/results/'.$result->exam_id));

        DB::table('notifications')->where('school_id', $user->school_id)->where('user_id', $user->id)->where(fn ($query) => $query->where('is_read', false)->orWhere('state', 'unread'))->latest()->limit(12)->get(['id', 'title', 'notification_type'])
            ->each(function ($notification) use ($tasks) {
                $type = in_array($notification->notification_type, ['attendance_alert', 'attendance'], true) ? 'attendance_alert' : 'communication';
                $this->add($tasks, $type, $notification->title, $type === 'attendance_alert' ? 'high' : 'normal', null, 'unread', $notification->id, '/learner/notifications/'.$notification->id);
            });

        DB::table('communications as communications')->join('communication_recipient_snapshots as recipients', 'recipients.communication_id', '=', 'communications.id')->leftJoin('announcement_reads as reads', fn ($join) => $join->on('reads.communication_id', '=', 'communications.id')->where('reads.user_id', $user->id))->where('communications.school_id', $user->school_id)->where('recipients.user_id', $user->id)->where('communications.communication_type', 'announcement')->where('communications.status', 'sent')->whereNull('reads.id')->latest('communications.sent_at')->limit(8)->get(['communications.id', 'communications.subject'])
            ->each(fn ($announcement) => $this->add($tasks, 'announcement', $announcement->subject, 'normal', null, 'unread', $announcement->id, '/learner/announcements/'.$announcement->id));

        DB::table('learner_sync_conflicts')->where('school_id', $user->school_id)->where('user_id', $user->id)->where('learner_id', $learner->id)->where('status', 'open')->latest()->limit(5)->get(['id'])
            ->each(fn ($conflict) => $this->add($tasks, 'sync_conflict', 'Offline change needs review', 'high', null, 'open', $conflict->id, '/learner/sync/conflicts'));

        $preference = DB::table('learner_dashboard_preferences')->where('school_id', $user->school_id)->where('learner_id', $learner->id)->first();
        if (! $preference || blank($preference->display_name)) {
            $this->add($tasks, 'profile_action_required', 'Complete your learner profile', 'low', null, 'incomplete', $learner->id, '/learner/profile');
        }

        return $tasks->unique(fn ($task) => $task['type'].'|'.$task['entity_reference'])->take(self::LIMIT)->values();
    }

    private function add(Collection $tasks, string $type, string $title, string $priority, mixed $dueDate, string $status, string $reference, string $link, ?string $learningArea = null): void
    {
        $tasks->push(['type' => $type, 'title' => strip_tags($title), 'priority' => $priority, 'due_date' => $dueDate, 'status' => $status, 'entity_reference' => $reference, 'deep_link' => $link, 'learning_area_id' => $learningArea]);
    }
}
