<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\BaseCrudController;
use App\Http\Resources\RecordOfWorkResource;
use App\Models\RecordOfWork;
use App\Services\Teaching\RecordOfWorkService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class RecordOfWorkController extends BaseCrudController
{
    private const MODULE = 'Records Of Work';

    private const RELATIONS = ['lessonPlan', 'curriculumCoverage'];

    public function __construct(private readonly RecordOfWorkService $service) {}

    public function index(Request $r)
    {
        $v = $r->validate(['status' => 'sometimes|in:completed,partially_completed,postponed,cancelled', 'date_from' => 'sometimes|date', 'date_to' => 'sometimes|date|after_or_equal:date_from', 'per_page' => 'sometimes|integer|min:1|max:100']);
        $q = RecordOfWork::with(self::RELATIONS)->current()->where('school_id', $this->school($r))->when(isset($v['status']), fn ($x) => $x->where('status', $v['status']))->when(isset($v['date_from']), fn ($x) => $x->whereDate('date_taught', '>=', $v['date_from']))->when(isset($v['date_to']), fn ($x) => $x->whereDate('date_taught', '<=', $v['date_to']));

        return $this->success(RecordOfWorkResource::collection($q->orderByDesc('date_taught')->paginate($v['per_page'] ?? 20)), 'Records of work retrieved successfully.');
    }

    public function show(Request $r, string $id)
    {
        $x = $this->q($r)->with(self::RELATIONS)->find($id);

        return $x ? $this->success(new RecordOfWorkResource($x), 'Record of work retrieved successfully.') : $this->notFound('Record of work not found.');
    }

    public function store(Request $r)
    {
        $v = $r->validate(['school_id' => 'sometimes|uuid|exists:schools,id', 'lesson_plan_id' => 'required|uuid|exists:lesson_plans,id', 'date_taught' => 'required|date', 'content_covered' => 'required|string', 'learner_response' => 'nullable|string', 'teacher_reflection' => 'nullable|string', 'status' => 'sometimes|in:completed,partially_completed,postponed,cancelled', 'created_by' => 'sometimes|nullable|uuid|exists:users,id']);
        $x = DB::transaction(fn () => $this->service->create($v, $this->school($r, $v), auth()->id()));

        return $this->created(new RecordOfWorkResource($x->load(self::RELATIONS)), 'Record of work created successfully.');
    }

    public function update(Request $r, string $id)
    {
        $x = $this->q($r)->find($id);
        if (! $x) {
            return $this->notFound('Record of work not found.');
        }$v = $r->validate(['date_taught' => 'sometimes|date|before_or_equal:today', 'content_covered' => 'sometimes|string', 'learner_response' => 'nullable|string', 'teacher_reflection' => 'nullable|string', 'status' => 'sometimes|in:completed,partially_completed,postponed,cancelled']);
        $x->update($v);

        return $this->success(new RecordOfWorkResource($x->refresh()->load(self::RELATIONS)), 'Record of work updated successfully.');
    }

    public function destroy(Request $r, string $id)
    {
        $x = $this->q($r)->find($id);
        if (! $x) {
            return $this->notFound('Record of work not found.');
        }$x->update(['is_deleted' => true, 'deleted_at' => now(), 'deleted_by' => auth()->id()]);

        return $this->success(null, 'Record of work deleted successfully.');
    }

    private function q(Request $r)
    {
        return RecordOfWork::current()->where('school_id', $this->school($r));
    }

    private function school(Request $r, array $v = []): string
    {
        $id = $r->attributes->get('tenant_school_id') ?? $v['school_id'] ?? $r->input('school_id');
        abort_if(! $id, 403, 'School context not found.');

        return (string) $id;
    }
}
