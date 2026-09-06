<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\BaseCrudController;
use App\Http\Resources\LessonPlanResource;
use App\Models\LessonPlan;
use App\Services\Teaching\LessonPlanService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class LessonPlanController extends BaseCrudController
{
    private const MODULE = 'Lesson Plans';

    private const RELATIONS = ['assignment', 'schemeLesson', 'notes', 'recordsOfWork'];

    public function __construct(private readonly LessonPlanService $service) {}

    public function index(Request $r)
    {
        $v = $r->validate(['teacher_assignment_id' => 'sometimes|uuid', 'status' => 'sometimes|in:draft,submitted,approved,rejected', 'date_from' => 'sometimes|date', 'date_to' => 'sometimes|date|after_or_equal:date_from', 'per_page' => 'sometimes|integer|min:1|max:100']);
        $q = LessonPlan::with(self::RELATIONS)->current()->where('school_id', $this->school($r));
        foreach (['teacher_assignment_id', 'status'] as $f) {
            $q->when(isset($v[$f]), fn ($x) => $x->where($f, $v[$f]));
        }$q->when(isset($v['date_from']), fn ($x) => $x->whereDate('lesson_date', '>=', $v['date_from']))->when(isset($v['date_to']), fn ($x) => $x->whereDate('lesson_date', '<=', $v['date_to']));

        return $this->success(LessonPlanResource::collection($q->orderBy('lesson_date')->paginate($v['per_page'] ?? 20)), 'Lesson plans retrieved successfully.');
    }

    public function show(Request $r, string $id)
    {
        $p = $this->query($r)->with(self::RELATIONS)->find($id);

        return $p ? $this->success(new LessonPlanResource($p), 'Lesson plan retrieved successfully.') : $this->notFound('Lesson plan not found.');
    }

    public function store(Request $r)
    {
        $v = $r->validate(['school_id' => 'sometimes|uuid|exists:schools,id', 'teacher_assignment_id' => 'required|uuid|exists:teacher_assignments,id', 'scheme_lesson_id' => 'required|uuid|exists:scheme_lessons,id', 'lesson_date' => 'required|date', 'introduction' => 'nullable|string', 'lesson_development' => 'required|string', 'conclusion' => 'nullable|string', 'reflection' => 'nullable|string', 'status' => 'sometimes|in:draft', 'created_by' => 'sometimes|nullable|uuid|exists:users,id']);
        $p = DB::transaction(function () use ($r, $v) {
            $p = $this->service->create($v, $this->school($r, $v), auth()->id());
            $this->audit($r, self::MODULE, 'Create', $p, null, $p->toArray(), 'Created lesson plan.');

            return $p;
        });

        return $this->created(new LessonPlanResource($p->load(self::RELATIONS)), 'Lesson plan created successfully.');
    }

    public function update(Request $r, string $id)
    {
        $p = $this->query($r)->find($id);
        if (! $p) {
            return $this->notFound('Lesson plan not found.');
        }$v = $r->validate(['lesson_date' => 'sometimes|date', 'introduction' => 'nullable|string', 'lesson_development' => 'sometimes|string', 'conclusion' => 'nullable|string', 'reflection' => 'nullable|string', 'status' => 'sometimes|in:draft,submitted,approved,rejected']);
        $old = $p->toArray();
        DB::transaction(function () use ($r, $p, $v, $old) {
            if (isset($v['status']) && $v['status'] !== $p->status) {
                $to = $v['status'];
                unset($v['status']);
                $this->service->transition($p, $to);
            }$p->update($v);
            $this->audit($r, self::MODULE, 'Update', $p, $old, $p->fresh()->toArray(), 'Updated lesson plan.');
        });

        return $this->success(new LessonPlanResource($p->refresh()->load(self::RELATIONS)), 'Lesson plan updated successfully.');
    }

    public function destroy(Request $r, string $id)
    {
        $p = $this->query($r)->find($id);
        if (! $p) {
            return $this->notFound('Lesson plan not found.');
        }if ($p->status === 'approved') {
            return $this->badRequest('Approved lesson plans cannot be deleted.');
        }DB::transaction(function () use ($r, $p) {
            $old = $p->toArray();
            $p->update(['is_deleted' => true, 'deleted_at' => now(), 'deleted_by' => auth()->id()]);
            $this->audit($r, self::MODULE, 'Delete', $p, $old, $p->toArray(), 'Soft deleted lesson plan.');
        });

        return $this->success(null, 'Lesson plan deleted successfully.');
    }

    private function query(Request $r)
    {
        return LessonPlan::current()->where('school_id', $this->school($r));
    }

    private function school(Request $r, array $v = []): string
    {
        $id = $r->attributes->get('tenant_school_id') ?? $v['school_id'] ?? $r->input('school_id');
        abort_if(! $id, 403, 'School context not found.');

        return (string) $id;
    }
}
