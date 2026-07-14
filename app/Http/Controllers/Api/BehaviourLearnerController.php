<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\BaseApiController;
use App\Http\Resources\BehaviourRecognitionResource;
use App\Models\BehaviourRecognition;
use App\Models\DisciplineAction;
use App\Services\LearnerPortal\LearnerPortalAccessService;

class BehaviourLearnerController extends BaseApiController
{
    public function __construct(private LearnerPortalAccessService $access) {}

    private function learner()
    {
        return $this->access->learner(auth()->user());
    }

    public function index()
    {
        $learner = $this->learner();

        return $this->success([
            'recognitions' => BehaviourRecognitionResource::collection($this->recognitionQuery($learner->school_id, $learner->id)->get()),
            'actions' => $this->actionQuery($learner->school_id, $learner->id)->get(),
        ]);
    }

    public function recognitions()
    {
        $l = $this->learner();

        return $this->success(BehaviourRecognitionResource::collection($this->recognitionQuery($l->school_id, $l->id)->get()));
    }

    public function actions()
    {
        $l = $this->learner();

        return $this->success($this->actionQuery($l->school_id, $l->id)->get());
    }

    private function recognitionQuery(string $schoolId, string $learnerId)
    {
        return BehaviourRecognition::where('school_id', $schoolId)->where('learner_id', $learnerId)->where('status', 'published')->where('visible_to_learner', true)->where('is_deleted', false);
    }

    private function actionQuery(string $schoolId, string $learnerId)
    {
        return DisciplineAction::where('school_id', $schoolId)->where('learner_id', $learnerId)->where('visible_to_learner', true)->where('is_deleted', false)->select('id', 'action_type', 'action_date', 'status', 'due_at', 'completed_at');
    }
}
