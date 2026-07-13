<?php

namespace App\Services\Homework;

use App\Models\HomeworkAssignmentLearner;
use App\Models\User;
use App\Services\ParentPortal\ParentPortalAccessService;

class HomeworkParentService
{
    public function __construct(private ParentPortalAccessService $access) {}

    public function records(User $u, string $learner)
    {
        $l = $this->access->requireLinkedLearner($u, $learner);

        return HomeworkAssignmentLearner::where('school_id', $u->school_id)->where('learner_id', $l->id)->with(['assignment.resources', 'submissions' => fn ($q) => $q->with(['mark' => fn ($m) => $m->where('status', 'released')])])->paginate(20);
    }

    public function record(User $u, string $learner, string $assignment)
    {
        $l = $this->access->requireLinkedLearner($u, $learner);

        return HomeworkAssignmentLearner::where('school_id', $u->school_id)->where('learner_id', $l->id)->where('assignment_id', $assignment)->with(['assignment.resources', 'submissions' => fn ($q) => $q->with(['mark' => fn ($m) => $m->where('status', 'released')])])->firstOrFail();
    }
}
