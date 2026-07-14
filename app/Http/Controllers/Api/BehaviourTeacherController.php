<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\BaseApiController;
use App\Http\Resources\BehaviourRecognitionResource;
use App\Http\Resources\DisciplineCaseResource;
use App\Http\Resources\DisciplineCategoryResource;
use App\Models\BehaviourRecognition;
use App\Models\DisciplineCategory;
use App\Services\Behaviour\BehaviourService;
use Illuminate\Http\Request;

class BehaviourTeacherController extends BaseApiController
{
    public function __construct(private BehaviourService $s) {}

    public function categories()
    {
        return $this->success(DisciplineCategoryResource::collection(DisciplineCategory::current()->where('school_id', auth()->user()->school_id)->get()));
    }

    public function learner(string $learner)
    {
        $this->s->authorizedLearner(auth()->user(), $learner);

        return $this->success(['cases' => DisciplineCaseResource::collection($this->s->teacherCases(auth()->user())->where('learner_id', $learner)->with('category', 'actions')->get()), 'recognitions' => BehaviourRecognitionResource::collection(BehaviourRecognition::where('school_id', auth()->user()->school_id)->where('learner_id', $learner)->where('is_deleted', false)->get())]);
    }

    public function cases()
    {
        return $this->success(DisciplineCaseResource::collection($this->s->teacherCases(auth()->user())->with('category', 'actions')->paginate(20)));
    }

    public function show(string $case)
    {
        return $this->success(new DisciplineCaseResource($this->s->teacherCases(auth()->user())->whereKey($case)->with('category', 'actions')->firstOrFail()));
    }

    public function store(Request $r)
    {
        $d = $r->validate(['learner_id' => 'required|uuid', 'category_id' => 'required|uuid', 'incident_date' => 'required|date', 'incident_time' => 'nullable', 'location' => 'nullable|string|max:255', 'description' => 'required|string|max:10000', 'severity' => 'nullable|string', 'priority' => 'nullable|in:low,medium,high,urgent', 'confidential' => 'sometimes|boolean', 'safeguarding' => 'sometimes|boolean']);

        return $this->created(new DisciplineCaseResource($this->s->report(auth()->user(), $d)));
    }

    public function action(Request $r, string $case)
    {
        $d = $r->validate(['action_type' => 'required|string|max:100', 'remarks' => 'nullable|string', 'assigned_to' => 'nullable|uuid', 'due_at' => 'nullable|date', 'follow_up_required' => 'sometimes|boolean', 'follow_up_at' => 'nullable|date', 'visible_to_learner' => 'sometimes|boolean', 'visible_to_parent' => 'sometimes|boolean']);

        return $this->created($this->s->action(auth()->user(), $case, $d));
    }

    public function recognize(Request $r)
    {
        $d = $r->validate(['learner_id' => 'required|uuid', 'category_id' => 'nullable|uuid', 'recognition_type' => 'required|in:commendation,badge,certificate,house_points,class_points,responsibility,leadership,service,improvement,other', 'title' => 'required|string|max:255', 'description' => 'nullable|string', 'points' => 'nullable|integer|min:0', 'visible_to_learner' => 'sometimes|boolean', 'visible_to_parent' => 'sometimes|boolean']);

        return $this->created(new BehaviourRecognitionResource($this->s->recognize(auth()->user(), $d)));
    }

    public function recognitions()
    {
        return $this->success(BehaviourRecognitionResource::collection(BehaviourRecognition::where('school_id', auth()->user()->school_id)->where('awarded_by', auth()->id())->where('is_deleted', false)->paginate(20)));
    }
}
