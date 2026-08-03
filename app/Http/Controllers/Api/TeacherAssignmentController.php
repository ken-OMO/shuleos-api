<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\BaseCrudController;
use App\Http\Resources\TeacherAssignmentResource;
use App\Models\TeacherAssignment;
use App\Services\Teaching\TeacherAssignmentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TeacherAssignmentController extends BaseCrudController
{
    private const MODULE = 'Teacher Assignments';

    private const RELATIONS = ['teacher', 'learningArea', 'grade', 'stream', 'academicYear', 'term'];

    public function __construct(private readonly TeacherAssignmentService $service) {}

    public function index(Request $request)
    {
        $schoolId = $this->schoolId($request);
        $validated = $request->validate([
            'teacher_id' => 'sometimes|uuid',
            'grade_id' => 'sometimes|uuid',
            'stream_id' => 'sometimes|uuid',
            'academic_year_id' => 'sometimes|uuid',
            'term_id' => 'sometimes|uuid',
            'active' => 'sometimes|boolean',
            'per_page' => 'sometimes|integer|min:1|max:100',
        ]);

        $query = TeacherAssignment::with(self::RELATIONS)->current()->where('school_id', $schoolId);

        foreach (['teacher_id', 'grade_id', 'stream_id', 'academic_year_id', 'term_id', 'active'] as $filter) {
            $query->when(array_key_exists($filter, $validated), fn ($q) => $q->where($filter, $validated[$filter]));
        }

        return $this->success(
            TeacherAssignmentResource::collection($query->orderByDesc('created_at')->paginate($validated['per_page'] ?? 20)),
            'Teacher assignments retrieved successfully.'
        );
    }

    public function show(Request $request, string $id)
    {
        $assignment = TeacherAssignment::with(self::RELATIONS)->current()
            ->where('school_id', $this->schoolId($request))->find($id);

        return $assignment
            ? $this->success(new TeacherAssignmentResource($assignment), 'Teacher assignment retrieved successfully.')
            : $this->notFound('Teacher assignment not found.');
    }

    public function store(Request $request)
    {
        $validated = $request->validate($this->storeRules());
        $schoolId = $this->schoolId($request, $validated);

        $assignment = DB::transaction(function () use ($request, $validated, $schoolId) {
            $assignment = $this->service->create($validated, $schoolId);
            $this->audit($request, self::MODULE, 'Create', $assignment, null, $assignment->toArray(), 'Created teacher assignment.');

            return $assignment;
        });

        return $this->created(
            new TeacherAssignmentResource($assignment->load(self::RELATIONS)),
            'Teacher assignment created successfully.'
        );
    }

    public function update(Request $request, string $id)
    {
        $assignment = TeacherAssignment::current()->where('school_id', $this->schoolId($request))->find($id);
        if (! $assignment) {
            return $this->notFound('Teacher assignment not found.');
        }

        $validated = $request->validate([
            'lessons_per_week' => 'sometimes|integer|min:1|max:20',
            'is_class_teacher' => 'sometimes|boolean',
            'active' => 'sometimes|boolean',
        ]);

        $oldValues = $assignment->toArray();
        $assignment = DB::transaction(function () use ($request, $assignment, $validated, $oldValues) {
            $assignment = $this->service->update($assignment, $validated);
            $this->audit($request, self::MODULE, 'Update', $assignment, $oldValues, $assignment->toArray(), 'Updated teacher assignment.');

            return $assignment;
        });

        return $this->success(new TeacherAssignmentResource($assignment->load(self::RELATIONS)), 'Teacher assignment updated successfully.');
    }

    public function destroy(Request $request, string $id)
    {
        $assignment = TeacherAssignment::current()->where('school_id', $this->schoolId($request))->find($id);
        if (! $assignment) {
            return $this->notFound('Teacher assignment not found.');
        }

        DB::transaction(function () use ($request, $assignment) {
            $oldValues = $assignment->toArray();
            $assignment->update(['active' => false, 'is_deleted' => true, 'deleted_at' => now(), 'deleted_by' => auth()->id()]);
            $this->audit($request, self::MODULE, 'Delete', $assignment, $oldValues, $assignment->toArray(), 'Soft deleted teacher assignment.');
        });

        return $this->success(null, 'Teacher assignment deleted successfully.');
    }

    private function schoolId(Request $request, array $validated = []): string
    {
        $schoolId = $request->attributes->get('tenant_school_id') ?? $validated['school_id'] ?? $request->input('school_id');
        abort_if(! $schoolId, 403, 'School context not found.');

        return (string) $schoolId;
    }

    private function storeRules(): array
    {
        return [
            'school_id' => 'sometimes|uuid|exists:schools,id',
            'teacher_id' => 'required|uuid|exists:teachers,id',
            'learning_area_id' => 'required|uuid|exists:learning_areas,id',
            'grade_id' => 'required|uuid|exists:grades,id',
            'stream_id' => 'nullable|uuid|exists:streams,id',
            'academic_year_id' => 'required|uuid|exists:academic_years,id',
            'term_id' => 'required|uuid|exists:terms,id',
            'lessons_per_week' => 'required|integer|min:1|max:20',
            'is_class_teacher' => 'sometimes|boolean',
            'active' => 'sometimes|boolean',
        ];
    }
}
