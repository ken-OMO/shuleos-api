<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\BaseApiController;
use App\Http\Resources\TeacherProfileResource;
use App\Services\TeacherPortal\TeacherPortalService;
use Illuminate\Http\Request;

class TeacherPortalController extends BaseApiController
{
    public function __construct(private readonly TeacherPortalService $s) {}

    private function u()
    {
        return auth()->user();
    }

    public function me()
    {
        return $this->success(new TeacherProfileResource($this->s->profile($this->u())));
    }

    public function dashboard()
    {
        return $this->success($this->s->dashboard($this->u()));
    }

    public function classes()
    {
        return $this->success($this->s->classes($this->u()));
    }

    public function learners()
    {
        return $this->success($this->s->learners($this->u()));
    }

    public function timetable(Request $r)
    {
        $v = $r->validate(['day' => 'sometimes|integer|min:1|max:7']);

        return $this->success($this->s->timetable($this->u(), $v['day'] ?? null));
    }

    public function timetableToday()
    {
        return $this->success($this->s->timetable($this->u(), now()->dayOfWeekIso));
    }

    public function timetableWeek()
    {
        return $this->success($this->s->timetable($this->u()));
    }

    public function timetableNext()
    {
        return $this->success($this->s->timetable($this->u(), now()->dayOfWeekIso)->first());
    }

    public function lessonPlans()
    {
        return $this->success($this->s->lessonPlans($this->u()));
    }

    public function lessonNotes()
    {
        return $this->success($this->s->lessonNotes($this->u()));
    }

    public function records()
    {
        return $this->success($this->s->records($this->u()));
    }

    public function coverage()
    {
        return $this->success($this->s->coverage($this->u()));
    }

    public function assessments()
    {
        return $this->success($this->s->assessments($this->u()));
    }

    public function attendance()
    {
        return $this->success($this->s->attendance($this->u()));
    }

    public function notifications()
    {
        return $this->success($this->s->notifications($this->u()));
    }

    public function announcements()
    {
        return $this->success($this->s->announcements($this->u()));
    }

    public function analytics()
    {
        return $this->success($this->s->analytics($this->u()));
    }

    public function preferences()
    {
        return $this->success($this->s->preferences($this->u()));
    }

    public function updatePreferences(Request $r)
    {
        $fields = ['show_todays_timetable', 'show_pending_lesson_plans', 'show_curriculum_coverage', 'show_notifications', 'show_announcements', 'show_attendance_summary', 'show_assessment_summary', 'show_performance_analytics'];
        $rules = array_fill_keys($fields, 'sometimes|boolean');

        return $this->success($this->s->updatePreferences($this->u(), $r->validate($rules)), 'Dashboard preferences updated.');
    }
}
