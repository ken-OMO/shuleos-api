<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\BaseCrudController;
use App\Http\Resources\SchemeOfWorkResource;
use App\Models\SchemeOfWork;
use App\Services\Teaching\SchemeOfWorkService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SchemeOfWorkController extends BaseCrudController
{
    private const MODULE = 'Schemes Of Work';
    private const RELATIONS = ['learningArea', 'grade', 'academicYear', 'term', 'lessons'];

    public function __construct(private readonly SchemeOfWorkService $service) {}

    public function index(Request $request)
    {
        $validated = $request->validate([
            'learning_area_id' => 'sometimes|uuid', 'grade_id' => 'sometimes|uuid',
            'academic_year_id' => 'sometimes|uuid', 'term_id' => 'sometimes|uuid',
            'active' => 'sometimes|boolean', 'per_page' => 'sometimes|integer|min:1|max:100',
        ]);
        $query = SchemeOfWork::with(self::RELATIONS)->current()->where('school_id', $this->schoolId($request));
        foreach (['learning_area_id', 'grade_id', 'academic_year_id', 'term_id', 'active'] as $filter) {
            $query->when(array_key_exists($filter, $validated), fn ($q) => $q->where($filter, $validated[$filter]));
        }
        return $this->success(SchemeOfWorkResource::collection($query->orderByDesc('created_at')->paginate($validated['per_page'] ?? 20)), 'Schemes of work retrieved successfully.');
    }

    public function show(Request $request, string $id)
    {
        $scheme = SchemeOfWork::with(self::RELATIONS)->current()->where('school_id', $this->schoolId($request))->find($id);
        return $scheme ? $this->success(new SchemeOfWorkResource($scheme), 'Scheme of work retrieved successfully.') : $this->notFound('Scheme of work not found.');
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'school_id' => 'sometimes|uuid|exists:schools,id', 'learning_area_id' => 'required|uuid|exists:learning_areas,id',
            'grade_id' => 'required|uuid|exists:grades,id', 'academic_year_id' => 'required|uuid|exists:academic_years,id',
            'term_id' => 'required|uuid|exists:terms,id', 'title' => 'required|string|max:255',
            'created_by' => 'sometimes|nullable|uuid|exists:users,id', 'active' => 'sometimes|boolean',
        ]);
        $schoolId = $this->schoolId($request, $validated);
        $scheme = DB::transaction(function () use ($request, $validated, $schoolId) {
            $scheme = $this->service->create($validated, $schoolId, auth()->id());
            $this->audit($request, self::MODULE, 'Create', $scheme, null, $scheme->toArray(), 'Created scheme of work.');
            return $scheme;
        });
        return $this->created(new SchemeOfWorkResource($scheme->load(self::RELATIONS)), 'Scheme of work created successfully.');
    }

    public function update(Request $request, string $id)
    {
        $scheme = SchemeOfWork::current()->where('school_id', $this->schoolId($request))->find($id);
        if (!$scheme) return $this->notFound('Scheme of work not found.');
        $validated = $request->validate(['title' => 'sometimes|string|max:255', 'active' => 'sometimes|boolean']);
        $old = $scheme->toArray();
        DB::transaction(function () use ($request, $scheme, $validated, $old) {
            $scheme->update($validated);
            $this->audit($request, self::MODULE, 'Update', $scheme, $old, $scheme->fresh()->toArray(), 'Updated scheme of work.');
        });
        return $this->success(new SchemeOfWorkResource($scheme->refresh()->load(self::RELATIONS)), 'Scheme of work updated successfully.');
    }

    public function destroy(Request $request, string $id)
    {
        $scheme = SchemeOfWork::current()->where('school_id', $this->schoolId($request))->find($id);
        if (!$scheme) return $this->notFound('Scheme of work not found.');
        DB::transaction(function () use ($request, $scheme) {
            $old = $scheme->toArray();
            $scheme->update(['active' => false, 'is_deleted' => true, 'deleted_at' => now(), 'deleted_by' => auth()->id()]);
            $this->audit($request, self::MODULE, 'Delete', $scheme, $old, $scheme->toArray(), 'Soft deleted scheme of work.');
        });
        return $this->success(null, 'Scheme of work deleted successfully.');
    }

    private function schoolId(Request $request, array $validated = []): string
    {
        $id = $request->attributes->get('tenant_school_id') ?? $validated['school_id'] ?? $request->input('school_id');
        abort_if(!$id, 403, 'School context not found.');
        return (string) $id;
    }
}
