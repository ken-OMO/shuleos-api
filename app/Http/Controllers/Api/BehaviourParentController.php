<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\BaseApiController;
use App\Http\Resources\BehaviourRecognitionResource;
use App\Models\BehaviourRecognition;
use App\Models\DisciplineAction;
use App\Services\ParentPortal\ParentPortalAccessService;

class BehaviourParentController extends BaseApiController
{
    public function __construct(private ParentPortalAccessService $access) {}

    private function learner(string $id)
    {
        return $this->access->requireLinkedLearner(auth()->user(), $id);
    }

    public function index(string $learner)
    {
        $linkedLearner = $this->learner($learner);

        return $this->success([
            'recognitions' => BehaviourRecognitionResource::collection($this->recognitionQuery($linkedLearner->school_id, $linkedLearner->id)->get()),
            'actions' => $this->actionQuery($linkedLearner->school_id, $linkedLearner->id)->get(),
        ]);
    }

    public function recognitions(string $learner)
    {
        $l = $this->learner($learner);

        return $this->success(BehaviourRecognitionResource::collection($this->recognitionQuery($l->school_id, $l->id)->get()));
    }

    public function actions(string $learner)
    {
        $l = $this->learner($learner);

        return $this->success($this->actionQuery($l->school_id, $l->id)->get());
    }

    private function recognitionQuery(string $schoolId, string $learnerId)
    {
        return BehaviourRecognition::where('school_id', $schoolId)->where('learner_id', $learnerId)->where('status', 'published')->where('visible_to_parent', true)->where('is_deleted', false);
    }

    private function actionQuery(string $schoolId, string $learnerId)
    {
        return DisciplineAction::where('school_id', $schoolId)->where('learner_id', $learnerId)->where('visible_to_parent', true)->where('is_deleted', false)->select('id', 'action_type', 'action_date', 'status', 'due_at', 'completed_at');
    }
}
