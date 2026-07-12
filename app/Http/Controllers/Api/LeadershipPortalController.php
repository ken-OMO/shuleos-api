<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\BaseApiController;
use App\Services\LeadershipPortal\LeadershipPortalService;
use Illuminate\Http\Request;

class LeadershipPortalController extends BaseApiController
{
    public function __construct(private readonly LeadershipPortalService $s) {}

    private function u()
    {
        return auth()->user();
    }

    public function me()
    {
        return $this->success($this->s->profile($this->u()));
    }

    public function dashboard()
    {
        return $this->success($this->s->dashboard($this->u()));
    }

    public function preferences()
    {
        return $this->success($this->s->preferences($this->u()));
    }

    public function updatePreferences(Request $r)
    {
        $rules = [];
        foreach (['attendance', 'teacher_attendance', 'curriculum_coverage', 'pending_approvals', 'lesson_plans', 'records_of_work', 'exams', 'report_cards', 'academic_performance', 'discipline', 'finance', 'announcements', 'notifications', 'teacher_workload', 'learner_enrolment'] as $f) {
            $rules['show_'.$f] = 'sometimes|boolean';
        }

        return $this->success($this->s->updatePreferences($this->u(), $r->validate($rules)));
    }

    public function attendance()
    {
        return $this->success($this->s->attendance($this->u()));
    }

    public function curriculum()
    {
        return $this->success($this->s->curriculum($this->u()));
    }

    public function approvals()
    {
        return $this->success($this->s->approvals($this->u()));
    }

    public function approval(string $id)
    {
        return $this->success($this->s->approval($this->u(), $id));
    }

    public function approve(Request $r, string $id)
    {
        $v = $r->validate(['comments' => 'sometimes|nullable|string']);

        return $this->success($this->s->decide($this->u(), $id, 'Approved', $v['comments'] ?? null));
    }

    public function reject(Request $r, string $id)
    {
        $v = $r->validate(['comments' => 'required|string']);

        return $this->success($this->s->decide($this->u(), $id, 'Rejected', $v['comments']));
    }

    public function lessonPlans()
    {
        return $this->success($this->s->teaching($this->u(), 'plans'));
    }

    public function records()
    {
        return $this->success($this->s->teaching($this->u(), 'records'));
    }

    public function workload()
    {
        return $this->success($this->s->workload($this->u()));
    }

    public function assessments()
    {
        return $this->success($this->s->assessments($this->u()));
    }

    public function reports()
    {
        return $this->success($this->s->reports($this->u()));
    }

    public function academic()
    {
        return $this->success($this->s->academic($this->u()));
    }

    public function discipline()
    {
        return $this->success($this->s->discipline($this->u()));
    }

    public function finance()
    {
        return $this->success($this->s->finance($this->u()));
    }

    public function announcements()
    {
        return $this->success($this->s->announcements($this->u()));
    }

    public function notifications()
    {
        return $this->success($this->s->notifications($this->u()));
    }
}
