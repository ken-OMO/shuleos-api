<?php

namespace App\Services\LearnerPortal;

use App\Models\LearnerDashboardPreference;
use App\Models\LearnerHelpRequest;
use App\Models\User;
use App\Services\Attendance\AttendanceReadService;
use App\Services\Communication\CommunicationNotificationService;
use App\Services\Timetable\CurrentPeriodService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class LearnerPortalPhaseTwoService
{
    private const HELP = ['academic_help', 'homework_clarification', 'timetable_issue', 'technical_support', 'wellbeing_support', 'safeguarding_concern'];

    public function __construct(private LearnerPortalAccessService $access, private LearnerTaskService $tasks, private LearnerOfflineResourceService $offline, private LearnerPortalAuditService $audit, private AttendanceReadService $attendance, private CurrentPeriodService $currentPeriod, private CommunicationNotificationService $communications) {}

    public function dashboard(User $user): array
    {
        $learner = $this->access->learner($user);
        $tasks = $this->tasks->tasks($user);
        $today = now()->dayOfWeekIso;
        $timetable = DB::table('timetable_entries as entries')->join('timetables', 'timetables.id', '=', 'entries.timetable_id')->where('timetables.school_id', $user->school_id)->where('timetables.status', 'published')->where('timetables.active', true)->where('entries.grade_id', $learner->grade_id)->where('entries.stream_id', $learner->stream_id)->where('entries.day_of_week', $today)->where('entries.is_deleted', false)->orderBy('entries.period_id')->limit(12)->get();
        $latestExamId = DB::table('learning_area_results as results')->join('exams', 'exams.id', '=', 'results.exam_id')->where('results.school_id', $user->school_id)->where('results.learner_id', $learner->id)->where('results.processing_status', 'processed')->where('exams.status', 'published')->orderByDesc('results.processed_at')->value('results.exam_id');
        $latestResult = $latestExamId ? DB::table('learning_area_results')->where('school_id', $user->school_id)->where('learner_id', $learner->id)->where('exam_id', $latestExamId)->where('processing_status', 'processed')->selectRaw('exam_id, ROUND(AVG(percentage), 2) AS average_percentage')->groupBy('exam_id')->first() : null;

        $attendance = $this->attendance->summary($this->attendance->learner($user)->whereDate('attendance_date', '>=', today()->subDays(89)));
        $announcements = $this->communications->announcements($user);

        return ['learner' => ['id' => $learner->id, 'name' => trim($learner->first_name.' '.$learner->last_name), 'grade_id' => $learner->grade_id, 'stream_id' => $learner->stream_id], 'academic_context' => ['academic_year' => DB::table('academic_years')->where('school_id', $user->school_id)->where('active', true)->select('id', 'year_name')->first(), 'term' => DB::table('terms')->where('school_id', $user->school_id)->where('active', true)->select('id', 'term_name')->first()], 'today_timetable' => $timetable, 'current_period' => $this->currentPeriod->current($user), 'homework_due_soon' => $tasks->where('type', 'homework_due')->take(10)->values(), 'overdue_homework' => $tasks->where('type', 'homework_overdue')->take(10)->values(), 'submissions_awaiting_feedback' => DB::table('homework_submissions')->where('school_id', $user->school_id)->where('learner_id', $learner->id)->whereIn('submission_status', ['submitted', 'late', 'resubmitted'])->count(), 'recently_returned_submissions' => $tasks->where('type', 'submission_returned')->take(10)->values(), 'tasks' => $tasks, 'unread_notifications' => DB::table('notifications')->where('school_id', $user->school_id)->where('user_id', $user->id)->where(fn ($query) => $query->where('is_read', false)->orWhere('state', 'unread'))->count(), 'announcements' => collect($announcements->items())->take(5)->values(), 'latest_result' => $latestResult, 'latest_report_card' => DB::table('report_cards')->where('school_id', $user->school_id)->where('learner_id', $learner->id)->where('status', 'published')->where('is_deleted', false)->latest('published_at')->select('id', 'exam_id', 'overall_grade', 'average_percentage', 'published_at')->first(), 'attendance_summary' => $attendance, 'upcoming_school_events' => $this->calendar($user, true)['items'], 'available_offline_resources' => $this->offline->index($user)->count(), 'last_sync_at' => DB::table('learner_sync_operations')->where('user_id', $user->id)->max('created_at'), 'active_devices' => DB::table('learner_portal_devices')->where('user_id', $user->id)->whereNull('revoked_at')->count(), 'last_refreshed_at' => now()->toIso8601String()];
    }

    public function calendar(User $user, bool $upcoming = false): array
    {
        $learner = $this->access->learner($user);
        $days = max(1, min(request()->integer('days', 30), 90));
        $from = today();
        $to = today()->addDays($days);
        $events = collect();
        DB::table('homework_assignment_learners as assigned')->join('homework_assignments as homework', 'homework.id', '=', 'assigned.assignment_id')->where('assigned.learner_id', $learner->id)->where('assigned.school_id', $user->school_id)->where('homework.status', 'published')->whereBetween('homework.due_at', [$from, $to])->limit(50)->get(['homework.id', 'homework.title', 'homework.due_at'])->each(fn ($item) => $events->push(['type' => 'homework_due', 'title' => $item->title, 'starts_at' => $item->due_at, 'entity_reference' => $item->id, 'deep_link' => '/learner/homework/'.$item->id]));
        DB::table('exams')->where('school_id', $user->school_id)->whereIn('status', ['published', 'scheduled'])->whereBetween('start_date', [$from, $to])->limit(30)->get(['id', 'exam_name', 'start_date'])->each(fn ($item) => $events->push(['type' => 'exam', 'title' => $item->exam_name, 'starts_at' => $item->start_date, 'entity_reference' => $item->id, 'deep_link' => '/learner/results/'.$item->id]));

        return ['range_days' => $days, 'items' => $events->unique(fn ($item) => $item['type'].'|'.$item['entity_reference'])->sortBy('starts_at')->values()->take($upcoming ? 20 : 80)];
    }

    public function communications(User $user, ?string $id = null)
    {
        $this->access->learner($user);
        $query = DB::table('communications as communications')->join('communication_recipient_snapshots as recipients', 'recipients.communication_id', '=', 'communications.id')->where('communications.school_id', $user->school_id)->where('recipients.user_id', $user->id)->whereIn('communications.status', ['sent', 'partially_failed'])->select(['communications.id', 'communications.communication_type', 'communications.subject', 'communications.body', 'communications.priority', 'communications.sent_at']);
        if ($id) {
            return $query->where('communications.id', $id)->firstOrFail();
        }

        return $query->latest('communications.sent_at')->paginate(20);
    }

    public function createHelp(User $user, array $data): LearnerHelpRequest
    {
        $learner = $this->access->learner($user);
        abort_unless(in_array($data['category'], self::HELP, true), 422);
        $today = DB::table('learner_help_requests')->where('school_id', $user->school_id)->where('learner_id', $learner->id)->whereDate('created_at', today())->count();
        abort_if($today >= config('learner_portal_phase_two.help_request_daily_limit', 10), 429, 'Daily help-request limit reached.');
        $destination = match ($data['category']) {
            'safeguarding_concern', 'wellbeing_support' => 'safeguarding_lead', 'technical_support' => 'school_admin', default => 'authorized_teacher'
        };

        return DB::transaction(function () use ($user, $learner, $data, $destination) {
            $request = LearnerHelpRequest::withoutGlobalScopes()->create(['id' => (string) Str::uuid(), 'school_id' => $user->school_id, 'learner_id' => $learner->id, 'created_by' => $user->id, 'category' => $data['category'], 'subject' => strip_tags($data['subject']), 'message' => strip_tags($data['message']), 'priority' => in_array($data['category'], ['safeguarding_concern', 'wellbeing_support'], true) ? 'high' : 'normal', 'status' => 'submitted', 'destination_role' => $destination, 'submitted_at' => now()]);
            DB::table('learner_help_request_history')->insert(['id' => (string) Str::uuid(), 'school_id' => $user->school_id, 'help_request_id' => $request->id, 'actor_user_id' => $user->id, 'action' => 'submitted', 'safe_metadata' => null, 'created_at' => now()]);
            $this->audit->record($user, 'help_request_submitted', 'learner_help_request', $request->id, ['category' => $data['category']]);

            return $request;
        });
    }

    public function help(User $user, ?string $id = null)
    {
        $learner = $this->access->learner($user);
        $query = LearnerHelpRequest::withoutGlobalScopes()->where('school_id', $user->school_id)->where('learner_id', $learner->id);

        return $id ? $query->whereKey($id)->firstOrFail() : $query->latest()->paginate(20);
    }

    public function preferences(User $user): LearnerDashboardPreference
    {
        $learner = $this->access->learner($user);

        return LearnerDashboardPreference::withoutGlobalScopes()->firstOrCreate(['school_id' => $user->school_id, 'learner_id' => $learner->id], ['id' => (string) Str::uuid(), 'show_fees' => false]);
    }

    public function updatePreferences(User $user, array $data): LearnerDashboardPreference
    {
        $preference = $this->preferences($user);
        if (! empty($data['profile_image_attachment_id'])) {
            $attachment = DB::table('learner_portal_attachments')->whereKey($data['profile_image_attachment_id'])->where('learner_id', $preference->learner_id)->where('context_type', 'profile_image')->whereIn('status', ['clean', 'attached'])->first();
            abort_unless($attachment, 422, 'A clean owned profile image is required.');
        }
        $data['version'] = $preference->version + 1;
        $preference->update($data);
        $this->audit->record($user, 'preferences_updated', 'learner_dashboard_preference', $preference->id, ['fields' => array_keys($data)]);

        return LearnerDashboardPreference::withoutGlobalScopes()->findOrFail($preference->id);
    }
}
