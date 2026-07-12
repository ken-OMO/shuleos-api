<?php

namespace App\Services\TeacherPortal;

use App\Models\CurriculumCoverage;
use App\Models\LessonNote;
use App\Models\LessonPlan;
use App\Models\RecordOfWork;
use App\Models\TeacherDashboardPreference;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class TeacherPortalService
{
    public function __construct(private readonly TeacherPortalAccessService $a) {}

    private function ids(User $u)
    {
        return $this->a->assignments($u)->pluck('id');
    }

    public function profile(User $u)
    {
        return $this->a->teacher($u)->load('school', 'assignments.grade', 'assignments.stream', 'assignments.learningArea');
    }

    public function classes(User $u)
    {
        return $this->a->assignments($u)->unique(fn ($x) => $x->grade_id.'-'.$x->stream_id)->values();
    }

    public function learners(User $u)
    {
        return $this->a->learners($u);
    }

    public function lessonPlans(User $u)
    {
        return LessonPlan::current()->where('school_id', $u->school_id)->whereIn('teacher_assignment_id', $this->ids($u))->with('assignment', 'schemeLesson')->latest('lesson_date')->paginate(20);
    }

    public function lessonNotes(User $u)
    {
        return LessonNote::current()->where('school_id', $u->school_id)->whereHas('lessonPlan', fn ($q) => $q->whereIn('teacher_assignment_id', $this->ids($u)))->with('lessonPlan')->latest()->paginate(20);
    }

    public function records(User $u)
    {
        return RecordOfWork::current()->where('school_id', $u->school_id)->whereHas('lessonPlan', fn ($q) => $q->whereIn('teacher_assignment_id', $this->ids($u)))->with('lessonPlan')->latest('date_taught')->paginate(20);
    }

    public function coverage(User $u)
    {
        return CurriculumCoverage::current()->where('school_id', $u->school_id)->whereIn('teacher_assignment_id', $this->ids($u))->latest('date_completed')->paginate(20);
    }

    public function timetable(User $u, ?int $day = null)
    {
        $t = $this->a->teacher($u);

        return DB::table('timetable_entries as e')->join('timetables as tt', 'tt.id', '=', 'e.timetable_id')->where('tt.school_id', $u->school_id)->where('e.teacher_id', $t->id)->when($day, fn ($q) => $q->where('e.day_of_week', $day))->orderBy('e.day_of_week')->orderBy('e.period_id')->get();
    }

    public function assessments(User $u)
    {
        $a = $this->a->assignments($u);

        return DB::table('exam_learning_areas as ela')->join('exams as e', 'e.id', '=', 'ela.exam_id')->where('e.school_id', $u->school_id)->where('e.is_deleted', false)->where(function ($q) use ($a) {
            foreach ($a as $x) {
                $q->orWhere(fn ($z) => $z->where('ela.learning_area_id', $x->learning_area_id));
            }
        })->select('ela.*', 'e.exam_name', 'e.status')->get();
    }

    public function attendance(User $u)
    {
        $learners = $this->a->learners($u)->pluck('id');

        return DB::table('learner_attendance')->where('school_id', $u->school_id)->whereIn('learner_id', $learners)->latest('attendance_date')->paginate(20);
    }

    public function notifications(User $u)
    {
        return DB::table('notifications')->where('school_id', $u->school_id)->where('user_id', $u->id)->latest('created_at')->paginate(20);
    }

    public function announcements(User $u)
    {
        return DB::table('broadcasts')->where('school_id', $u->school_id)->whereRaw('LOWER(status)=?', ['sent'])->whereNotNull('sent_at')->where(fn ($q) => $q->whereNull('target_group')->orWhereRaw('LOWER(target_group) IN (?,?)', ['teacher', 'teachers']))->latest('sent_at')->get();
    }

    public function analytics(User $u)
    {
        $ids = $this->ids($u);
        $coverage = CurriculumCoverage::current()->where('school_id', $u->school_id)->whereIn('teacher_assignment_id', $ids);
        $total = $coverage->count();

        return ['assigned_learners' => $this->a->learners($u)->count(), 'pending_lesson_plans' => LessonPlan::current()->where('school_id', $u->school_id)->whereIn('teacher_assignment_id', $ids)->whereNotIn('status', ['approved', 'published'])->count(), 'pending_records_of_work' => RecordOfWork::current()->where('school_id', $u->school_id)->whereHas('lessonPlan', fn ($q) => $q->whereIn('teacher_assignment_id', $ids))->whereNotIn('status', ['completed', 'approved'])->count(), 'coverage_percentage' => $total ? round(($coverage->where('completed', true)->count() / $total) * 100, 2) : null];
    }

    public function preferences(User $u)
    {
        $t = $this->a->teacher($u);

        return TeacherDashboardPreference::firstOrCreate(['school_id' => $u->school_id, 'teacher_id' => $t->id], ['id' => (string) Str::uuid()]);
    }

    public function updatePreferences(User $u, array $d)
    {
        $p = $this->preferences($u);
        $p->update($d);

        return $p;
    }

    public function dashboard(User $u)
    {
        $p = $this->preferences($u);
        $data = ['profile' => $this->profile($u), 'classes' => $this->classes($u)];
        if ($p->show_todays_timetable) {
            $data['todays_timetable'] = $this->timetable($u, (int) now()->dayOfWeekIso);
        }if ($p->show_pending_lesson_plans) {
            $data['pending_lesson_plans'] = $this->analytics($u)['pending_lesson_plans'];
        }if ($p->show_curriculum_coverage) {
            $data['curriculum_coverage'] = $this->analytics($u)['coverage_percentage'];
        }if ($p->show_notifications) {
            $data['notifications'] = $this->notifications($u);
        }if ($p->show_announcements) {
            $data['announcements'] = $this->announcements($u);
        }if ($p->show_attendance_summary) {
            $data['attendance_summary'] = $this->attendance($u);
        }if ($p->show_assessment_summary) {
            $data['assessment_summary'] = $this->assessments($u);
        }if ($p->show_performance_analytics) {
            $data['performance_analytics'] = $this->analytics($u);
        }$data['preferences'] = $p;

        return $data;
    }
}
