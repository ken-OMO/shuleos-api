<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\BaseApiController;
use App\Http\Resources\HomeworkAssignmentResource;
use App\Http\Resources\HomeworkSubmissionResource;
use App\Services\Homework\HomeworkAssignmentService;
use App\Services\Homework\HomeworkMarkingService;
use App\Services\Homework\HomeworkRubricService;
use App\Services\Homework\HomeworkSubmissionFileService;
use Illuminate\Http\Request;

class HomeworkTeacherController extends BaseApiController
{
    public function __construct(private HomeworkAssignmentService $s, private HomeworkMarkingService $m, private HomeworkRubricService $rubrics, private HomeworkSubmissionFileService $files) {}

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

    public function submission(string $assignment, string $submission)
    {
        $a = $this->s->ownQuery(auth()->user())->whereKey($assignment)->firstOrFail();

        return $this->success(new HomeworkSubmissionResource($a->submissions()->whereKey($submission)->with('files', 'mark')->firstOrFail()));
    }

    public function rubric(Request $r, string $assignment)
    {
        if ($r->isMethod('get')) {
            return $this->success($this->rubrics->get(auth()->user(), $assignment));
        }$data = $r->validate(['title' => 'required|string|max:255', 'description' => 'nullable|string', 'criteria' => 'required|array|min:1', 'criteria.*.criterion' => 'required|string|max:255', 'criteria.*.description' => 'nullable|string', 'criteria.*.maximum_points' => 'nullable|numeric|min:0', 'criteria.*.display_order' => 'sometimes|integer|min:0', 'criteria.*.levels' => 'sometimes|array', 'criteria.*.levels.*.level_name' => 'required|string|max:255', 'criteria.*.levels.*.description' => 'nullable|string', 'criteria.*.levels.*.points' => 'nullable|numeric|min:0', 'criteria.*.levels.*.competency_code' => 'nullable|string|max:50', 'criteria.*.levels.*.display_order' => 'sometimes|integer|min:0']);

        return $this->success($this->rubrics->save(auth()->user(), $assignment, $data));
    }

    public function download(string $assignment, string $submission, string $file)
    {
        return $this->files->teacherDownload(auth()->user(), $assignment, $submission, $file, $this->s);
    }

    public function returnSubmission(Request $r, string $assignment, string $submission, bool $resubmit = false)
    {
        $d = $r->validate(['reason' => 'required|string|max:4000']);

        return $this->success($this->m->returnSubmission(auth()->user(), $assignment, $submission, $d['reason'], $resubmit));
    }

    public function mark(Request $r, string $assignment, string $submission)
    {
        $d = $r->validate(['raw_score' => 'nullable|numeric|min:0', 'competency_level' => 'nullable|string|max:100', 'teacher_feedback' => 'nullable|string|max:10000', 'private_teacher_notes' => 'nullable|string|max:10000', 'revision_reason' => 'nullable|string|max:4000', 'status' => 'sometimes|in:draft,marked', 'rubric_scores' => 'sometimes|array', 'rubric_scores.*.criterion_id' => 'required|uuid', 'rubric_scores.*.level_id' => 'nullable|uuid', 'rubric_scores.*.points_awarded' => 'nullable|numeric|min:0', 'rubric_scores.*.comment' => 'nullable|string|max:2000']);

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
