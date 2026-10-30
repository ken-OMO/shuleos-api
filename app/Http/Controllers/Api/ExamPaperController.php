<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\BaseCrudController;
use App\Http\Resources\ExamPaperResource;
use App\Models\ExamPaper;
use App\Services\Assessment\AssessmentTenantContext;
use App\Services\Assessment\ExamPaperService;
use Illuminate\Http\Request;

class ExamPaperController extends BaseCrudController
{
    private const RELATIONS = ['examLearningArea'];

    public function __construct(private readonly ExamPaperService $service) {}

    public function index(Request $r)
    {
        $v = $r->validate(['exam_learning_area_id' => 'sometimes|uuid', 'per_page' => 'sometimes|integer|min:1|max:100']);
        $q = ExamPaper::with(self::RELATIONS)->current()->whereHas('examLearningArea.exam', fn ($x) => $x->where('school_id', $this->school($r))->where('is_deleted', false))->when(isset($v['exam_learning_area_id']), fn ($x) => $x->where('exam_learning_area_id', $v['exam_learning_area_id']));

        return $this->success(ExamPaperResource::collection($q->orderBy('paper_number')->paginate($v['per_page'] ?? 20)), 'Exam papers retrieved successfully.');
    }

    public function show(Request $r, string $id)
    {
        $x = $this->q($r)->with(self::RELATIONS)->find($id);

        return $x ? $this->success(new ExamPaperResource($x), 'Exam paper retrieved successfully.') : $this->notFound('Exam paper not found.');
    }

    public function store(Request $r)
    {
        $v = $r->validate(['school_id' => 'sometimes|uuid|exists:schools,id', 'exam_learning_area_id' => 'required|uuid|exists:exam_learning_areas,id', 'paper_name' => 'required|string|max:255', 'paper_number' => 'required|integer|min:1|max:10', 'max_marks' => 'required|integer|min:1|max:1000']);
        $x = $this->service->create($v, $this->school($r, $v));

        return $this->created(new ExamPaperResource($x->load(self::RELATIONS)), 'Exam paper created successfully.');
    }

    public function update(Request $r, string $id)
    {
        $x = $this->q($r)->find($id);
        if (! $x) {
            return $this->notFound('Exam paper not found.');
        }
        $v = $r->validate(['paper_name' => 'sometimes|string|max:255', 'max_marks' => 'sometimes|integer|min:1|max:1000']);
        $x = $this->service->update($x, $v, $this->school($r));

        return $this->success(new ExamPaperResource($x->refresh()->load(self::RELATIONS)), 'Exam paper updated successfully.');
    }

    public function destroy(Request $r, string $id)
    {
        $x = $this->q($r)->find($id);
        if (! $x) {
            return $this->notFound('Exam paper not found.');
        }
        $this->service->delete($x, $this->school($r), auth()->id());

        return $this->success(null, 'Exam paper deleted successfully.');
    }

    private function q(Request $r)
    {
        return ExamPaper::current()->whereHas('examLearningArea.exam', fn ($x) => $x->where('school_id', $this->school($r))->where('is_deleted', false));
    }

    private function school(Request $r, array $v = []): string
    {
        return AssessmentTenantContext::schoolId($r);
    }
}
