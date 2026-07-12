<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\BaseCrudController;
use App\Http\Resources\LearningAreaResultResource;
use App\Models\LearningAreaResult;
use App\Services\Assessment\ResultProcessingService;
use Illuminate\Http\Request;

class LearningAreaResultController extends BaseCrudController
{
    private const RELATIONS = ['exam', 'learner.grade.educationLevel', 'learningArea', 'gradingSystem', 'gradingScale', 'processedBy'];

    public function __construct(private readonly ResultProcessingService $service) {}

    public function index(Request $request)
    {
        $validated = $request->validate([
            'exam_id' => 'sometimes|uuid', 'learner_id' => 'sometimes|uuid',
            'learning_area_id' => 'sometimes|uuid', 'per_page' => 'sometimes|integer|min:1|max:100',
        ]);
        $query = $this->query($request)->with(self::RELATIONS);
        foreach (['exam_id', 'learner_id', 'learning_area_id'] as $field) {
            $query->when(isset($validated[$field]), fn ($q) => $q->where($field, $validated[$field]));
        }

        return $this->success(LearningAreaResultResource::collection($query->paginate($validated['per_page'] ?? 20)), 'Learning area results retrieved successfully.');
    }

    public function show(Request $request, string $id)
    {
        $result = $this->query($request)->with(self::RELATIONS)->find($id);

        return $result ? $this->success(new LearningAreaResultResource($result), 'Learning area result retrieved successfully.') : $this->notFound('Learning area result not found.');
    }

    public function process(Request $request)
    {
        $validated = $request->validate([
            'school_id' => 'sometimes|uuid|exists:schools,id',
            'exam_learning_area_id' => 'required|uuid|exists:exam_learning_areas,id',
            'learner_id' => 'required|uuid|exists:learners,id',
        ]);
        $result = $this->service->process($this->school($request, $validated), $validated['exam_learning_area_id'], $validated['learner_id'], (string) auth()->id());

        return $this->success(new LearningAreaResultResource($result), 'Learning area result processed successfully.');
    }

    private function query(Request $request)
    {
        return LearningAreaResult::current()->where('school_id', $this->school($request));
    }

    private function school(Request $request, array $validated = []): string
    {
        $id = $request->attributes->get('tenant_school_id') ?? $validated['school_id'] ?? $request->input('school_id');
        abort_if(! $id, 403, 'School context not found.');

        return (string) $id;
    }
}
