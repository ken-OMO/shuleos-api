<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\BaseCrudController;
use App\Http\Resources\MeritListResource;
use App\Models\MeritList;
use App\Services\Assessment\MeritListService;
use Illuminate\Http\Request;

class MeritListController extends BaseCrudController
{
    private const RELATIONS = ['exam', 'learner', 'grade', 'stream', 'overallGradingSystem', 'overallGradingScale', 'generatedBy'];

    public function __construct(private readonly MeritListService $service) {}

    public function index(Request $request)
    {
        $validated = $request->validate(['exam_id' => 'sometimes|uuid', 'grade_id' => 'sometimes|uuid', 'stream_id' => 'sometimes|uuid', 'status' => 'sometimes|in:generated,published', 'per_page' => 'sometimes|integer|min:1|max:100']);
        $query = MeritList::current()->where('school_id', $this->school($request))->with(self::RELATIONS);
        foreach (['exam_id', 'grade_id', 'stream_id', 'status'] as $field) {
            $query->when(isset($validated[$field]), fn ($q) => $q->where($field, $validated[$field]));
        }
        $query->orderBy('school_position')->orderBy('learner_id');

        return $this->success(MeritListResource::collection($query->paginate($validated['per_page'] ?? 20)), 'Merit lists retrieved successfully.');
    }

    public function show(Request $request, string $id)
    {
        $row = MeritList::current()->where('school_id', $this->school($request))->with(self::RELATIONS)->find($id);

        return $row ? $this->success(new MeritListResource($row), 'Merit-list row retrieved successfully.') : $this->notFound('Merit-list row not found.');
    }

    public function generate(Request $request)
    {
        $data = $this->validatedAction($request);
        $rows = $this->service->generate($this->school($request, $data), $data['exam_id'], $data['grade_id'] ?? null, $data['stream_id'] ?? null, (string) auth()->id());

        return $this->success(MeritListResource::collection($rows), 'Merit list generated successfully.');
    }

    public function publish(Request $request)
    {
        $data = $this->validatedAction($request);
        $rows = $this->service->publish($this->school($request, $data), $data['exam_id'], $data['grade_id'] ?? null, $data['stream_id'] ?? null);

        return $this->success(MeritListResource::collection($rows), 'Merit list published successfully.');
    }

    private function validatedAction(Request $request): array
    {
        return $request->validate(['school_id' => 'sometimes|uuid|exists:schools,id', 'exam_id' => 'required|uuid|exists:exams,id', 'grade_id' => 'sometimes|nullable|uuid|exists:grades,id', 'stream_id' => 'sometimes|nullable|uuid|exists:streams,id']);
    }

    private function school(Request $request, array $data = []): string
    {
        $id = $request->attributes->get('tenant_school_id') ?? $data['school_id'] ?? $request->input('school_id');
        abort_if(! $id, 403, 'School context not found.');

        return (string) $id;
    }
}
