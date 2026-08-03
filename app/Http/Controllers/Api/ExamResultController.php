<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\BaseCrudController;
use App\Http\Resources\ExamResultResource;
use App\Models\ExamResult;
use App\Services\Assessment\ExamResultService;
use Illuminate\Http\Request;

class ExamResultController extends BaseCrudController
{
    private const RELATIONS = ['exam', 'learner', 'learningArea', 'paper'];

    public function __construct(private readonly ExamResultService $service) {}

    public function index(Request $r)
    {
        $v = $r->validate(['exam_id' => 'sometimes|uuid', 'learner_id' => 'sometimes|uuid', 'paper_id' => 'sometimes|uuid', 'per_page' => 'sometimes|integer|min:1|max:100']);
        $q = ExamResult::with(self::RELATIONS)->current()->whereHas('exam', fn ($x) => $x->where('school_id', $this->school($r))->where('is_deleted', false));
        foreach (['exam_id', 'learner_id', 'paper_id'] as $f) {
            $q->when(isset($v[$f]), fn ($x) => $x->where($f, $v[$f]));
        }

return $this->success(ExamResultResource::collection($q->paginate($v['per_page'] ?? 20)), 'Exam results retrieved successfully.');
    }

    public function show(Request $r, string $id)
    {
        $x = $this->q($r)->with(self::RELATIONS)->find($id);

        return $x ? $this->success(new ExamResultResource($x), 'Exam result retrieved successfully.') : $this->notFound('Exam result not found.');
    }

    public function store(Request $r)
    {
        $v = $r->validate(['school_id' => 'sometimes|uuid|exists:schools,id', 'learner_id' => 'required|uuid|exists:learners,id', 'paper_id' => 'required|uuid|exists:exam_papers,id', 'marks' => 'required|numeric|min:0', 'entered_by' => 'sometimes|nullable|uuid|exists:users,id']);
        $x = $this->service->create($v, $this->school($r, $v), auth()->id());

        return $this->created(new ExamResultResource($x->load(self::RELATIONS)), 'Exam result created successfully.');
    }

    public function update(Request $r, string $id)
    {
        $x = $this->q($r)->find($id);
        if (! $x) {
            return $this->notFound('Exam result not found.');
        }if ($x->exam->status === 'closed') {
            return $this->badRequest('Closed exam results cannot be changed.');
        }$v = $r->validate(['marks' => 'required|numeric|min:0|max:'.$x->paper->max_marks]);
        $x->update($v);

        return $this->success(new ExamResultResource($x->refresh()->load(self::RELATIONS)), 'Exam result updated successfully.');
    }

    public function destroy(Request $r, string $id)
    {
        $x = $this->q($r)->find($id);
        if (! $x) {
            return $this->notFound('Exam result not found.');
        }if ($x->exam->status === 'closed') {
            return $this->badRequest('Closed exam results cannot be deleted.');
        }$x->update(['is_deleted' => true, 'deleted_at' => now(), 'deleted_by' => auth()->id()]);

        return $this->success(null, 'Exam result deleted successfully.');
    }

    private function q(Request $r)
    {
        return ExamResult::current()->whereHas('exam', fn ($x) => $x->where('school_id', $this->school($r))->where('is_deleted', false));
    }

    private function school(Request $r, array $v = []): string
    {
        $id = $r->attributes->get('tenant_school_id') ?? $v['school_id'] ?? $r->input('school_id');
        abort_if(! $id,403,'School context not found.');

        return (string) $id;
    }
}
