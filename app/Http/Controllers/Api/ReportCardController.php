<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\BaseCrudController;
use App\Http\Requests\Assessment\ReportCardSelectionRequest;
use App\Http\Resources\ReportCardResource;
use App\Models\ReportCard;
use App\Services\Assessment\ReportCardService;
use Illuminate\Http\Request;

class ReportCardController extends BaseCrudController
{
    private const RELATIONS = ['learner', 'exam', 'academicYear', 'term', 'meritList', 'grade', 'stream', 'overallGradingSystem', 'overallGradingScale', 'pathwayRecommendation', 'generatedBy', 'publishedBy', 'learningAreas.learningArea', 'learningAreas.gradingScale'];

    public function __construct(private readonly ReportCardService $service) {}

    public function index(Request $r)
    {
        $v = $r->validate(['exam_id' => 'sometimes|uuid', 'learner_id' => 'sometimes|uuid', 'grade_id' => 'sometimes|uuid', 'stream_id' => 'sometimes|uuid', 'status' => 'sometimes|in:generated,published,stale', 'per_page' => 'sometimes|integer|min:1|max:100']);
        $q = ReportCard::current()->where('school_id', $this->school($r))->with(self::RELATIONS);
        foreach (['exam_id', 'learner_id', 'grade_id', 'stream_id', 'status'] as $f) {
            $q->when(isset($v[$f]), fn ($x) => $x->where($f, $v[$f]));
        }

        return $this->success(ReportCardResource::collection($q->paginate($v['per_page'] ?? 20)), 'Report cards retrieved successfully.');
    }

    public function show(Request $r, string $id)
    {
        $x = ReportCard::current()->where('school_id', $this->school($r))->with(self::RELATIONS)->find($id);

        return $x ? $this->success(new ReportCardResource($x), 'Report card retrieved successfully.') : $this->notFound('Report card not found.');
    }

    public function generate(ReportCardSelectionRequest $r)
    {
        $mode = $r->validate([
            'response_mode' => 'sometimes|in:details,summary',
        ]);
        $v = $this->action($r);

        if (($mode['response_mode'] ?? 'details') === 'summary') {
            $summary = $this->service->generateSummary(
                $this->school($r, $v), $v['exam_id'],
                $v['learner_id'] ?? null, $v['grade_id'] ?? null,
                $v['stream_id'] ?? null, (string) auth()->id(), $v
            );

            return $this->success($summary, 'Report cards generated successfully.');
        }

        $rows = $this->service->generate($this->school($r, $v), $v['exam_id'], $v['learner_id'] ?? null, $v['grade_id'] ?? null, $v['stream_id'] ?? null, (string) auth()->id(), $v);

        return $this->success(ReportCardResource::collection($rows), 'Report cards generated successfully.');
    }

    public function updateComments(Request $r, string $id)
    {
        $v = $r->validate(['class_teacher_comment' => 'sometimes|nullable|string', 'principal_comment' => 'sometimes|nullable|string', 'learning_areas' => 'sometimes|array', 'learning_areas.*.id' => 'required|uuid', 'learning_areas.*.teacher_comment' => 'nullable|string']);
        $x = $this->service->updateComments($this->school($r), $id, $v);

        return $this->success(new ReportCardResource($x), 'Report card comments updated successfully.');
    }

    public function publish(ReportCardSelectionRequest $r)
    {
        $v = $this->action($r);
        $rows = $this->service->publish($this->school($r, $v), $v['exam_id'], $v['learner_id'] ?? null, $v['grade_id'] ?? null, $v['stream_id'] ?? null, (string) auth()->id());

        return $this->success(ReportCardResource::collection($rows), 'Report cards published successfully.');
    }

    private function action(ReportCardSelectionRequest $r): array
    {
        return $r->validated();
    }

    private function school(Request $r, array $v = []): string
    {
        $id = auth()->user()?->school_id;
        abort_if(! $id, 403, 'School context not found.');

        $tenant = $r->attributes->get('tenant_school_id');
        abort_if(
            $tenant !== null && (string) $tenant !== (string) $id,
            403,
            'School context is invalid.'
        );

        return (string) $id;
    }
}
