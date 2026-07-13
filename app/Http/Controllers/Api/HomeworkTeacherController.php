<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\BaseApiController;
use App\Http\Resources\HomeworkAssignmentResource;
use App\Http\Resources\HomeworkSubmissionResource;
use App\Services\Homework\HomeworkAssignmentService;
use App\Services\Homework\HomeworkMarkingService;
use Illuminate\Http\Request;

class HomeworkTeacherController extends BaseApiController
{
    public function __construct(private HomeworkAssignmentService $s, private HomeworkMarkingService $m) {}

    public function index()
    {
        return $this->success(HomeworkAssignmentResource::collection($this->s->ownQuery(auth()->user())->paginate(20)));
    }

    public function show(string $assignment)
    {
        return $this->success(new HomeworkAssignmentResource($this->s->ownQuery(auth()->user())->whereKey($assignment)->with('resources', 'rubric.criteria.levels')->firstOrFail()));
    }

    public function store(Request $r)
    {
        return $this->created(new HomeworkAssignmentResource($this->s->create(auth()->user(), $this->data($r))));
    }

    public function update(Request $r, string $assignment)
    {
        return $this->success(new HomeworkAssignmentResource($this->s->update(auth()->user(), $assignment, $this->data($r, true))));
    }

    public function resource(Request $r, string $assignment)
    {
        $d = $r->validate(['learning_resource_id' => 'required|uuid', 'required' => 'sometimes|boolean', 'display_order' => 'sometimes|integer|min:0']);
        $this->s->attachResource(auth()->user(), $assignment, $d['learning_resource_id'], $d['required'] ?? false, $d['display_order'] ?? 0);

        return $this->success(null);
    }

    public function transition(string $assignment, string $status)
    {
        return $this->success(new HomeworkAssignmentResource($this->s->transition(auth()->user(), $assignment, $status)));
    }

    public function learners(string $assignment)
    {
        $a = $this->s->ownQuery(auth()->user())->whereKey($assignment)->firstOrFail();

        return $this->success($a->learners()->with('learner')->paginate(50));
    }

    public function submissions(string $assignment)
    {
        $a = $this->s->ownQuery(auth()->user())->whereKey($assignment)->firstOrFail();

        return $this->success(HomeworkSubmissionResource::collection($a->submissions()->with('files', 'mark')->paginate(50)));
    }

    public function mark(Request $r, string $assignment, string $submission)
    {
        $d = $r->validate(['raw_score' => 'nullable|numeric|min:0', 'competency_level' => 'nullable|string|max:100', 'teacher_feedback' => 'nullable|string|max:10000', 'private_teacher_notes' => 'nullable|string|max:10000', 'status' => 'sometimes|in:draft,marked']);

        return $this->success($this->m->mark(auth()->user(), $assignment, $submission, $d));
    }

    public function release(string $assignment, string $submission)
    {
        return $this->success($this->m->release(auth()->user(), $assignment, $submission));
    }

    private function data(Request $r, bool $partial = false): array
    {
        $x = $partial ? 'sometimes' : 'required';

        return $r->validate(['teacher_assignment_id' => "$x|uuid", 'scheme_lesson_id' => 'nullable|uuid', 'lesson_plan_id' => 'nullable|uuid', 'title' => "$x|string|max:255", 'instructions' => "$x|string", 'assignment_type' => "$x|in:homework,classwork,project,research,practical,holiday_assignment,cat,take_home_assessment,remedial_work,revision_exercise,group_assignment", 'submission_mode' => "$x|in:text,file,text_and_file,external_link,no_submission,mixed", 'total_marks' => 'nullable|numeric|min:0', 'grading_mode' => "$x|in:marks,percentage,rubric,competency,ungraded", 'publish_at' => 'nullable|date', 'due_at' => "$x|date", 'allow_late_submission' => 'sometimes|boolean', 'late_penalty_type' => 'nullable|in:none,fixed_marks,percentage', 'late_penalty_value' => 'nullable|numeric|min:0', 'maximum_attempts' => 'sometimes|integer|min:1|max:20', 'allow_resubmission' => 'sometimes|boolean', 'show_marks_immediately' => 'sometimes|boolean', 'show_feedback_immediately' => 'sometimes|boolean']);
    }
}
