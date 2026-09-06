<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\BaseCrudController;
use App\Http\Resources\ExamLearningAreaResource;
use App\Models\ExamLearningArea;
use App\Services\Assessment\ExamLearningAreaService;
use Illuminate\Http\Request;

class ExamLearningAreaController extends BaseCrudController
{
    private const MODULE = 'Exam Learning Areas';

    private const RELATIONS = ['exam', 'learningArea', 'papers'];

    public function __construct(private readonly ExamLearningAreaService $service) {}

    public function index(Request $r)
    {
        $v = $r->validate(['exam_id' => 'sometimes|uuid', 'learning_area_id' => 'sometimes|uuid', 'per_page' => 'sometimes|integer|min:1|max:100']);
        $q = ExamLearningArea::with(self::RELATIONS)->current()->whereHas('exam', fn ($x) => $x->where('school_id', $this->school($r))->where('is_deleted', false));
        foreach (['exam_id', 'learning_area_id'] as $f) {
            $q->when(isset($v[$f]), fn ($x) => $x->where($f, $v[$f]));
        }

        return $this->success(ExamLearningAreaResource::collection($q->orderByDesc('created_at')->paginate($v['per_page'] ?? 20)), 'Exam learning areas retrieved successfully.');
    }

    public function show(Request $r, string $id)
    {
        $x = $this->q($r)->with(self::RELATIONS)->find($id);

        return $x ? $this->success(new ExamLearningAreaResource($x), 'Exam learning area retrieved successfully.') : $this->notFound('Exam learning area not found.');
    }

    public function store(Request $r)
    {
        $v = $r->validate(['school_id' => 'sometimes|uuid|exists:schools,id', 'exam_id' => 'required|uuid|exists:exams,id', 'learning_area_id' => 'required|uuid|exists:learning_areas,id', 'number_of_papers' => 'required|integer|min:1|max:10', 'total_marks' => 'required|integer|min:1|max:1000']);
        $x = $this->service->create($v, $this->school($r, $v));
        $this->audit($r, self::MODULE, 'Create', $x, null, $x->toArray(), 'Attached learning area to exam.');

        return $this->created(new ExamLearningAreaResource($x->load(self::RELATIONS)), 'Exam learning area created successfully.');
    }

    public function update(Request $r, string $id)
    {
        $x = $this->q($r)->find($id);
        if (! $x) {
            return $this->notFound('Exam learning area not found.');
        }if ($x->exam->status !== 'draft' || $x->papers()->exists()) {
            return $this->badRequest('Exam learning areas with papers or a published exam cannot be changed.');
        }$v = $r->validate(['number_of_papers' => 'sometimes|integer|min:1|max:10', 'total_marks' => 'sometimes|integer|min:1|max:1000']);
        $x->update($v);

        return $this->success(new ExamLearningAreaResource($x->refresh()->load(self::RELATIONS)), 'Exam learning area updated successfully.');
    }

    public function destroy(Request $r, string $id)
    {
        $x = $this->q($r)->find($id);
        if (! $x) {
            return $this->notFound('Exam learning area not found.');
        }if ($x->exam->status !== 'draft' || $x->papers()->exists()) {
            return $this->badRequest('Only paperless learning areas on draft exams can be deleted.');
        }$x->update(['is_deleted' => true, 'deleted_at' => now(), 'deleted_by' => auth()->id()]);

        return $this->success(null, 'Exam learning area deleted successfully.');
    }

    private function q(Request $r)
    {
        return ExamLearningArea::current()->whereHas('exam', fn ($x) => $x->where('school_id', $this->school($r))->where('is_deleted', false));
    }

    private function school(Request $r, array $v = []): string
    {
        $id = $r->attributes->get('tenant_school_id') ?? $v['school_id'] ?? $r->input('school_id');
        abort_if(! $id, 403, 'School context not found.');

        return (string) $id;
    }
}
