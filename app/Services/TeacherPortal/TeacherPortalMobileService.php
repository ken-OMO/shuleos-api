<?php

namespace App\Services\TeacherPortal;

use App\Models\CurriculumCoverage;
use App\Models\LessonNote;
use App\Models\LessonPlan;
use App\Models\RecordOfWork;
use App\Models\SchemeOfWork;
use App\Models\User;
use App\Services\Communication\CommunicationNotificationService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class TeacherPortalMobileService
{
    public function __construct(private TeacherPortalAccessService $access, private TeacherPortalService $portal, private CommunicationNotificationService $notifications) {}

    public function assignments(User $user)
    {
        return $this->access->assignments($user)->map(function ($assignment) use ($user) {
            $assignment->learner_count = DB::table('learners')->where('school_id', $user->school_id)->where('grade_id', $assignment->grade_id)->where('stream_id', $assignment->stream_id)->where('active', true)->where('is_deleted', false)->count();

            return $assignment;
        });
    }

    public function classes(User $user)
    {
        return $this->assignments($user)->unique(fn ($a) => $a->grade_id.'|'.$a->stream_id)->values();
    }

    public function learners(User $user, ?string $assignmentId = null, ?string $streamId = null)
    {
        $assignments = $assignmentId ? collect([$this->access->requireAssignment($user, $assignmentId)]) : ($streamId ? collect([$this->access->requireStream($user, $streamId)]) : $this->access->assignments($user));
        $query = DB::table('learners')->where('school_id', $user->school_id)->where('active', true)->where('is_deleted', false)->where(function ($query) use ($assignments) {
            foreach ($assignments as $assignment) {
                $query->orWhere(fn ($q) => $q->where('grade_id', $assignment->grade_id)->where('stream_id', $assignment->stream_id));
            }
        })->select('id', 'admission_no', 'first_name', 'middle_name', 'last_name', 'grade_id', 'stream_id', 'active')->orderBy('first_name');

        return $query->paginate(config('teacher_portal.pagination_max', 50));
    }

    public function schemes(User $user)
    {
        $assignments = $this->access->assignments($user);

        return SchemeOfWork::current()->where('school_id', $user->school_id)->where(function ($query) use ($assignments) {
            foreach ($assignments as $a) {
                $query->orWhere(fn ($q) => $q->where('learning_area_id', $a->learning_area_id)->where('grade_id', $a->grade_id)->where('academic_year_id', $a->academic_year_id)->where('term_id', $a->term_id));
            }
        });
    }

    public function plans(User $user)
    {
        return LessonPlan::current()->where('school_id', $user->school_id)->whereIn('teacher_assignment_id', $this->access->assignments($user)->pluck('id'));
    }

    public function notes(User $user)
    {
        return LessonNote::current()->where('school_id', $user->school_id)->whereHas('lessonPlan', fn ($q) => $q->whereIn('teacher_assignment_id', $this->access->assignments($user)->pluck('id')));
    }

    public function records(User $user)
    {
        return RecordOfWork::current()->where('school_id', $user->school_id)->whereHas('lessonPlan', fn ($q) => $q->whereIn('teacher_assignment_id', $this->access->assignments($user)->pluck('id')));
    }

    public function coverage(User $user)
    {
        return CurriculumCoverage::current()->where('school_id', $user->school_id)->whereIn('teacher_assignment_id', $this->access->assignments($user)->pluck('id'));
    }

    public function assessments(User $user)
    {
        $areas = $this->access->assignments($user)->pluck('learning_area_id');

        return DB::table('exam_learning_areas as area')->join('exams as exam', 'exam.id', '=', 'area.exam_id')->where('exam.school_id', $user->school_id)->where('exam.status', 'published')->where('exam.is_deleted', false)->whereIn('area.learning_area_id', $areas)->select('exam.id as exam_id', 'exam.exam_name', 'exam.start_date', 'exam.end_date', 'area.id as exam_learning_area_id', 'area.learning_area_id', 'area.number_of_papers', 'area.total_marks')->paginate(20);
    }

    public function papers(User $user, string $examId)
    {
        $areas = $this->access->assignments($user)->pluck('learning_area_id');

        return DB::table('exam_papers as paper')->join('exam_learning_areas as area', 'area.id', '=', 'paper.exam_learning_area_id')->join('exams as exam', 'exam.id', '=', 'area.exam_id')->where('exam.id', $examId)->where('exam.school_id', $user->school_id)->where('exam.status', 'published')->whereIn('area.learning_area_id', $areas)->where('paper.is_deleted', false)->select('paper.id', 'paper.paper_name', 'paper.paper_number', 'paper.max_marks', 'area.learning_area_id', 'exam.id as exam_id')->get();
    }

    public function communications(User $user, ?string $id = null)
    {
        $this->access->teacher($user);
        $query = DB::table('communications')->where('school_id', $user->school_id)->where('sender_user_id', $user->id)->when($id, fn ($q) => $q->where('id', $id))->select('id', 'communication_type', 'category', 'priority', 'subject', 'body', 'status', 'scheduled_for', 'sent_at', 'created_at')->latest();

        return $id ? $query->firstOrFail() : $query->paginate(30);
    }

    public function calendar(User $user, string $from, string $to): array
    {
        $start = CarbonImmutable::parse($from)->startOfDay();
        $end = CarbonImmutable::parse($to)->endOfDay();
        abort_if($start->diffInDays($end) > config('teacher_portal.calendar_max_days', 90), 422, 'Calendar range exceeds the maximum.');
        $events = collect();
        DB::table('terms')->where('school_id', $user->school_id)->where('active', true)->whereDate('end_date', '>=', $start)->whereDate('start_date', '<=', $end)->get()->each(fn ($x) => $events->push($this->event('term', $x->id, $x->term_name, $x->start_date, $x->end_date, true)));
        DB::table('exams')->where('school_id', $user->school_id)->whereIn('status', ['published', 'completed'])->where('is_deleted', false)->whereDate('end_date', '>=', $start)->whereDate('start_date', '<=', $end)->get()->each(fn ($x) => $events->push($this->event('exam', $x->id, $x->exam_name, $x->start_date, $x->end_date, true)));
        $this->portal->timetable($user)->each(fn ($x) => $events->push($this->event('lesson', $x->id, 'Scheduled lesson', $x->day_of_week, $x->day_of_week, false)));

        return $events->unique(fn ($x) => $x['type'].'|'.$x['id'].'|'.$x['start'])->take(100)->values()->all();
    }

    public function analytics(User $user): array
    {
        $base = $this->portal->analytics($user);
        $teacher = $this->access->teacher($user);
        $workflow = Schema::hasTable('teacher_workflows') ? DB::table('teacher_workflows')->where('school_id', $user->school_id)->where('teacher_id', $teacher->id) : null;
        $batches = Schema::hasTable('mark_entry_batches') ? DB::table('mark_entry_batches')->where('school_id', $user->school_id)->where('teacher_id', $teacher->id) : null;
        $phaseTwo = [
            'workflows_submitted' => $workflow ? (clone $workflow)->whereIn('state', ['submitted', 'under_review'])->count() : 0,
            'workflows_approved' => $workflow ? (clone $workflow)->where('state', 'approved')->count() : 0,
            'workflows_changes_requested' => $workflow ? (clone $workflow)->where('state', 'changes_requested')->count() : 0,
            'mark_batches_draft' => $batches ? (clone $batches)->where('status', 'draft')->count() : 0,
            'mark_batches_submitted' => $batches ? (clone $batches)->where('status', 'submitted')->count() : 0,
            'mark_batches_changes_requested' => $batches ? (clone $batches)->where('status', 'changes_requested')->count() : 0,
            'unresolved_sync_conflicts' => Schema::hasTable('teacher_sync_conflicts') ? DB::table('teacher_sync_conflicts')->where('school_id', $user->school_id)->where('user_id', $user->id)->where('status', 'open')->count() : 0,
        ];

        return $base + ['assignment_count' => $this->access->assignments($user)->count(), 'weekly_teaching_load' => (int) $this->access->assignments($user)->sum('lessons_per_week'), 'unread_notifications' => $this->notifications->unreadCount($user)] + $phaseTwo;
    }

    public function dashboard(User $user): array
    {
        $limit = config('teacher_portal.dashboard_limit', 10);

        return ['teacher' => $this->access->teacher($user), 'current_period' => DB::table('terms')->where('school_id', $user->school_id)->where('active', true)->whereDate('start_date', '<=', today())->whereDate('end_date', '>=', today())->first(), 'assignments' => $this->assignments($user)->take($limit), 'todays_timetable' => $this->portal->timetable($user, now()->dayOfWeekIso)->take($limit), 'analytics' => $this->analytics($user), 'announcements' => collect($this->portal->announcements($user))->take($limit), 'recent_communications' => $this->communications($user)->items(), 'last_refreshed_at' => now()->toIso8601String()];
    }

    private function event(string $type, string $id, string $title, mixed $start, mixed $end, bool $allDay): array
    {
        return compact('id', 'type', 'title', 'start', 'end') + ['all_day' => $allDay];
    }
}
