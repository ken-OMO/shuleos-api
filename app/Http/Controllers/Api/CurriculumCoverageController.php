<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\BaseCrudController;
use App\Http\Resources\CurriculumCoverageResource;
use App\Models\CurriculumCoverage;
use App\Services\Teaching\CurriculumCoverageService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CurriculumCoverageController extends BaseCrudController
{
    private const MODULE = 'Curriculum Coverage';
    private const RELATIONS = ['teacherAssignment', 'scheme', 'schemeLesson', 'recordOfWork'];

    public function __construct(private readonly CurriculumCoverageService $service) {}

    public function index(Request $request)
    {
        $v = $request->validate([
            'teacher_assignment_id' => 'sometimes|uuid', 'scheme_id' => 'sometimes|uuid',
            'completed' => 'sometimes|boolean', 'week_number' => 'sometimes|integer|min:1',
            'per_page' => 'sometimes|integer|min:1|max:100',
        ]);
        $q = CurriculumCoverage::with(self::RELATIONS)->current()->where('school_id', $this->school($request));
        foreach (['teacher_assignment_id', 'scheme_id', 'completed', 'week_number'] as $filter) {
            $q->when(array_key_exists($filter, $v), fn ($x) => $x->where($filter, $v[$filter]));
        }
        return $this->success(CurriculumCoverageResource::collection($q->orderByDesc('date_completed')->paginate($v['per_page'] ?? 20)), 'Curriculum coverage records retrieved successfully.');
    }

    public function show(Request $request, string $id)
    {
        $coverage = $this->query($request)->with(self::RELATIONS)->find($id);
        return $coverage ? $this->success(new CurriculumCoverageResource($coverage), 'Curriculum coverage record retrieved successfully.') : $this->notFound('Curriculum coverage record not found.');
    }

    public function store(Request $request)
    {
        $v = $request->validate(['school_id' => 'sometimes|uuid|exists:schools,id', 'record_of_work_id' => 'required|uuid|exists:records_of_work,id']);
        $coverage = DB::transaction(function () use ($request, $v) {
            $coverage = $this->service->create($v['record_of_work_id'], $this->school($request, $v));
            $this->audit($request, self::MODULE, 'Create', $coverage, null, $coverage->toArray(), 'Derived curriculum coverage from record of work.');
            return $coverage;
        });
        return $this->created(new CurriculumCoverageResource($coverage->load(self::RELATIONS)), 'Curriculum coverage created successfully.');
    }

    public function update(Request $request, string $id)
    {
        $coverage = $this->query($request)->find($id);
        if (!$coverage) return $this->notFound('Curriculum coverage record not found.');
        $v = $request->validate(['completed' => 'required|boolean']);
        $old = $coverage->toArray();
        $coverage->update($v);
        $this->audit($request, self::MODULE, 'Update', $coverage, $old, $coverage->fresh()->toArray(), 'Updated curriculum completion state.');
        return $this->success(new CurriculumCoverageResource($coverage->refresh()->load(self::RELATIONS)), 'Curriculum coverage updated successfully.');
    }

    public function destroy(Request $request, string $id)
    {
        $coverage = $this->query($request)->find($id);
        if (!$coverage) return $this->notFound('Curriculum coverage record not found.');
        $coverage->update(['is_deleted' => true, 'deleted_at' => now(), 'deleted_by' => auth()->id()]);
        return $this->success(null, 'Curriculum coverage deleted successfully.');
    }

    private function query(Request $request) { return CurriculumCoverage::current()->where('school_id', $this->school($request)); }
    private function school(Request $request, array $v = []): string
    {
        $id = $request->attributes->get('tenant_school_id') ?? $v['school_id'] ?? $request->input('school_id');
        abort_if(!$id, 403, 'School context not found.');
        return (string) $id;
    }
}
