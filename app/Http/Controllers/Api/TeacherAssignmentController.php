<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\BaseCrudController;
use App\Http\Resources\TeacherAssignmentResource;
use App\Models\TeacherAssignment;
use App\Services\Administrator\AdministratorAuditService;
use App\Services\Auth\AuthContextService;
use App\Services\School\SchoolBusinessWriteAccess;
use App\Services\Teaching\TeacherAssignmentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

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

    public function classTeacherAppointments(Request $request)
    {
        $schoolId = $this->workspaceSchool($request);
        $today = today('Africa/Nairobi')->toDateString();
        $rows = DB::table('class_teachers as appointment')
            ->join('teachers as teacher', 'teacher.id', '=', 'appointment.teacher_id')
            ->join('users as account', 'account.id', '=', 'teacher.user_id')
            ->join('grades as grade', 'grade.id', '=', 'appointment.grade_id')
            ->join('streams as stream', 'stream.id', '=', 'appointment.stream_id')
            ->join('terms as term', 'term.id', '=', 'appointment.term_id')
            ->join('academic_years as year', 'year.id', '=', 'appointment.academic_year_id')
            ->where('appointment.school_id', $schoolId)
            ->where('teacher.school_id', $schoolId)->where('account.school_id', $schoolId)
            ->where('grade.school_id', $schoolId)->where('stream.school_id', $schoolId)
            ->where('term.school_id', $schoolId)->where('year.school_id', $schoolId)
            ->orderByDesc('term.start_date')->orderByDesc('appointment.created_at')->orderBy('appointment.id')
            ->get([
                'appointment.id', 'appointment.active', 'appointment.temporary_until',
                'account.first_name', 'account.last_name', 'account.active as account_active',
                'account.is_deleted as account_deleted', 'account.suspended_at',
                'teacher.active as teacher_active', 'teacher.is_deleted as teacher_deleted',
                'grade.grade_name', 'grade.active as grade_active', 'grade.grade_number',
                'stream.stream_name', 'stream.active as stream_active',
                'term.term_name', 'term.start_date', 'term.end_date', 'term.active as term_active',
                'year.year_name', 'year.active as year_active', 'year.start_date as year_start', 'year.end_date as year_end',
            ]);

        return $this->success($rows->map(function ($row) use ($today) {
            $status = match (true) {
                ! $row->active => 'Inactive appointment',
                ! $row->teacher_active || $row->teacher_deleted || ! $row->account_active
                    || $row->account_deleted || $row->suspended_at !== null => 'Teacher profile or account inactive',
                ! $row->grade_active || ! $row->stream_active || $row->grade_number === null => 'Grade or stream unavailable',
                ! $row->term_active || ! $row->year_active => 'Term or academic year inactive',
                ($row->temporary_until !== null && $row->temporary_until < $today)
                    || $row->end_date < $today || $row->year_end < $today => 'Ended',
                $row->start_date > $today || $row->year_start > $today => 'Upcoming',
                default => 'Current',
            };

            return [
                'id' => $row->id,
                'teacher' => trim($row->first_name.' '.$row->last_name),
                'grade' => $row->grade_name,
                'stream' => $row->stream_name,
                'year' => $row->year_name,
                'term' => $row->term_name,
                'starts_on' => $row->start_date,
                'ends_on' => $row->end_date,
                'temporary_until' => $row->temporary_until,
                'status' => $status,
            ];
        }));
    }

    public function appointClassTeacher(Request $request, SchoolBusinessWriteAccess $access)
    {
        $schoolId = $this->workspaceSchool($request);
        $today = today('Africa/Nairobi')->toDateString();
        $details = $request->validate([
            'teacher_id' => 'required|uuid',
            'stream_id' => 'required|uuid',
            'term_id' => 'required|uuid',
            'temporary_until' => 'nullable|date_format:Y-m-d|after_or_equal:'.$today,
        ]);

        return $access->run($schoolId, function () use ($schoolId, $details, $today, $request) {
            $this->workspaceSchool($request);
            $active = fn (string $table) => DB::table($table)->where('school_id', $schoolId)->where('active', true);
            $stream = $active('streams')->where('id', $details['stream_id'])->first();
            $term = $active('terms')->where('id', $details['term_id'])->whereDate('end_date', '>=', $today)->first();
            $teacher = $active('teachers')->where('id', $details['teacher_id'])->where('is_deleted', false)->first();
            abort_unless($stream && $term && $teacher
                && $active('grades')->where('id', $stream->grade_id)->exists()
                && $active('academic_years')->where('id', $term->academic_year_id)->exists(), 422,
                'Select an active teacher, class and term from this school.');

            $until = $details['temporary_until'] ?? null;
            abort_if($until && ($until < $term->start_date || $until > $term->end_date), 422,
                'The temporary appointment must end within the selected term.');
            $appointments = $active('class_teachers')->where('term_id', $term->id)
                ->where(fn ($query) => $query->whereNull('temporary_until')->orWhereDate('temporary_until', '>=', $today));
            $legacy = $active('teacher_assignments')->where('term_id', $term->id)
                ->where('is_deleted', false)->where('is_class_teacher', true);
            abort_if((clone $appointments)->where('stream_id', $stream->id)->exists()
                || (clone $legacy)->where('stream_id', $stream->id)->exists(), 409,
                'This stream already has a class teacher for the selected term.');

            $teacherAppointments = (clone $appointments)->where('teacher_id', $teacher->id);
            if ($until === null) {
                abort_if((clone $teacherAppointments)->whereNull('temporary_until')->exists()
                    || (clone $legacy)->where('teacher_id', $teacher->id)->exists(), 409,
                    'This teacher already has a regular class appointment for the term. Enter an end date for temporary cover.');
            } else {
                abort_if((clone $teacherAppointments)->whereNotNull('temporary_until')->exists(), 409,
                    'This teacher already has temporary class cover in the selected term.');
            }

            $id = (string) Str::uuid();
            DB::table('class_teachers')->insert($details + [
                'id' => $id, 'school_id' => $schoolId, 'grade_id' => $stream->grade_id,
                'academic_year_id' => $term->academic_year_id, 'active' => true,
            ]);

            app(AdministratorAuditService::class)->record($request->user(), 'class_teacher_appointed', 'class_teachers', $id, [], $details);

            return $this->created(['id' => $id], 'Class teacher appointed.');
        });
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
