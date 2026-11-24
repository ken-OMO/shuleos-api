<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\BaseCrudController;
use App\Http\Resources\TeacherAssignmentResource;
use App\Models\TeacherAssignment;
use App\Services\Auth\AuthContextService;
use App\Services\School\SchoolBusinessWriteAccess;
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

    private function workspaceSchool(Request $request): string
    {
        $auth = app(AuthContextService::class);
        $actor = $request->user();
        $context = $auth->resolve($actor);
        $roles = $context['roles'];

        abort_unless($context['scope'] === 'school' && $context['school_id'] === $request->attributes->get('tenant_school_id')
            && ! $request->attributes->get('client_supplied_school_id')
            && ((in_array('Headteacher', $roles, true) && $auth->hasPermission($actor, 'manage_academics'))
                || (in_array('School Admin', $roles, true) && $auth->hasPermission($actor, 'access_administrator_portal'))), 403);

        return (string) $context['school_id'];
    }

    public function workspaceOptions(Request $request)
    {
        $schoolId = $this->workspaceSchool($request);
        $active = fn (string $table) => DB::table($table)->where('school_id', $schoolId)->where('active', true);

        return $this->success([
            'teachers' => DB::table('teachers')->join('users', 'users.id', '=', 'teachers.user_id')
                ->where('teachers.school_id', $schoolId)->where('users.school_id', $schoolId)
                ->where('teachers.active', true)->where('teachers.is_deleted', false)
                ->where('users.active', true)->where('users.is_deleted', false)->whereNull('users.suspended_at')
                ->orderBy('users.last_name')->orderBy('users.first_name')
                ->get(['teachers.id', 'users.first_name', 'users.last_name']),
            'grades' => $active('grades')->orderBy('grade_order')->get(['id', 'grade_name']),
            'streams' => $active('streams')->orderBy('stream_name')->get(['id', 'grade_id', 'stream_name']),
            'years' => $active('academic_years')->orderByDesc('start_date')->get(['id', 'year_name']),
            'terms' => $active('terms')->orderBy('start_date')->get(['id', 'academic_year_id', 'term_name']),
            'areas' => DB::table('learning_area_allocations')->join('learning_areas', 'learning_areas.id', '=', 'learning_area_allocations.learning_area_id')
                ->where('learning_area_allocations.school_id', $schoolId)->where('learning_area_allocations.active', true)
                ->where('learning_areas.active', true)->distinct()->orderBy('learning_areas.learning_area_name')
                ->get(['learning_area_allocations.grade_id', 'learning_areas.id', 'learning_areas.learning_area_name']),
        ]);
    }

    public function workspaceStore(Request $request, SchoolBusinessWriteAccess $access)
    {
        $schoolId = $this->workspaceSchool($request);
        $request->validate(['is_class_teacher' => 'prohibited', 'active' => 'prohibited']);

        return $access->run($schoolId, function () use ($request) {
            $this->workspaceSchool($request);

            return $this->store($request);
        });
    }

    public function store(Request $request)
    {
        $validated = $request->validate($this->storeRules());
        $schoolId = $this->schoolId($request, $validated);

        $validated['stream_id'] ??= null;

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
