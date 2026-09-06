<?php

namespace App\Services\LeadershipPortal;

use App\Models\User;
use App\Services\Auth\AuthContextService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class LeadershipPortalAccessService
{
    public function __construct(private AuthContextService $authContext) {}

    private const LEADERSHIP_ROLES = [
        'principal', 'deputy_principal', 'headteacher', 'deputy_headteacher', 'director',
        'school_owner', 'hod', 'school_admin', 'finance_officer', 'finance_manager',
        'examination_officer', 'discipline_lead', 'timetable_manager', 'senior_teacher',
    ];

    private const WHOLE_SCHOOL_ROLES = [
        'principal', 'deputy_principal', 'headteacher', 'deputy_headteacher', 'school_admin',
    ];

    private const EXECUTIVE_ROLES = ['director', 'school_owner'];

    public function scope(User $user): array
    {
        abort_unless($user->active && ! $user->is_deleted && $user->school_id, 403, 'Active school tenant required.');
        $roleName = $user->role?->role_name;
        $role = $this->normalizeRole($roleName);
        if ($role === 'platform_owner') {
            throw new AuthorizationException('Platform Owner must use platform administration scope.');
        }
        if (! in_array($role, self::LEADERSHIP_ROLES, true)) {
            throw new AuthorizationException('School leadership role required.');
        }

        $learningAreas = [];
        $teachers = [];
        if ($role === 'hod') {
            $teacher = DB::table('teachers')
                ->where('user_id', $user->id)
                ->where('school_id', $user->school_id)
                ->where('active', true)
                ->where('is_deleted', false)
                ->first();
            if (! $teacher) {
                throw new AuthorizationException('Active HOD teacher profile required.');
            }
            $learningAreas = DB::table('hod_assignments')
                ->where('school_id', $user->school_id)
                ->where('teacher_id', $teacher->id)
                ->where('active', true)
                ->pluck('learning_area_id')
                ->unique()
                ->values()
                ->all();
            if ($learningAreas === []) {
                throw new AuthorizationException('No active HOD assignment exists.');
            }
            $teachers = DB::table('teacher_assignments')
                ->where('school_id', $user->school_id)
                ->whereIn('learning_area_id', $learningAreas)
                ->where('active', true)
                ->where('is_deleted', false)
                ->pluck('teacher_id')
                ->unique()
                ->values()
                ->all();
        }

        $wholeSchool = in_array($role, self::WHOLE_SCHOOL_ROLES, true)
            && ($this->has($user, 'view_principal_dashboard') || $this->has($user, 'view_deputy_dashboard') || $this->hasAny($user, ['view_leadership_dashboard', 'access_school_leadership_portal']));

        return [
            'school_id' => $user->school_id,
            'role' => $roleName,
            'role_key' => $role,
            'whole_school' => $wholeSchool,
            'executive_summary_only' => in_array($role, self::EXECUTIVE_ROLES, true),
            'learning_area_ids' => $learningAreas,
            'teacher_ids' => $teachers,
            'finance' => $this->hasAny($user, ['view_finance_oversight', 'view_school_finance_summary']),
            'attendance' => $this->hasAny($user, ['view_attendance_intelligence', 'view_school_attendance_summary']),
            'curriculum' => $this->hasAny($user, ['view_hod_department_analytics', 'view_school_curriculum_summary']),
            'workload' => $this->has($user, 'view_teacher_workload'),
            'discipline' => $this->hasAny($user, ['view_behaviour_oversight', 'view_school_discipline_summary']),
            'academic' => $this->hasAny($user, ['view_academic_insights', 'view_school_academic_summary']),
            'approvals' => $this->hasAny($user, ['review_cross_module_approvals', 'view_leadership_approvals']),
            'manage_approvals' => $this->hasAny($user, ['review_cross_module_approvals', 'manage_leadership_approvals']),
        ];
    }

    public function has(User $user, string $permission): bool
    {
        return $this->authContext->hasPermission(
            $user,
            $permission
        );
    }

    public function hasAny(User $user, array $permissions): bool
    {
        foreach ($permissions as $permission) {
            if ($this->has($user, $permission)) {
                return true;
            }
        }

        return false;
    }

    public function require(User $user, string $permission): void
    {
        $this->scope($user);
        if (! $this->has($user, $permission)) {
            throw new AuthorizationException('Permission denied.');
        }
    }

    public function assertWholeSchool(User $user): array
    {
        $scope = $this->scope($user);
        if (! $scope['whole_school']) {
            throw new AuthorizationException('Whole-school leadership scope required.');
        }

        return $scope;
    }

    public function applyTeacherScope(Builder $query, User $user, string $column = 'teacher_id'): Builder
    {
        $scope = $this->scope($user);
        if ($scope['role_key'] === 'hod') {
            $query->whereIn($column, $scope['teacher_ids']);
        }

        return $query;
    }

    public function assertTeacher(User $user, string $teacherId): void
    {
        $scope = $this->scope($user);
        $exists = DB::table('teachers')
            ->where('id', $teacherId)
            ->where('school_id', $scope['school_id'])
            ->where('active', true)
            ->where('is_deleted', false)
            ->exists();
        if (! $exists || ($scope['role_key'] === 'hod' && ! in_array($teacherId, $scope['teacher_ids'], true))) {
            throw new AuthorizationException('Teacher is outside leadership scope.');
        }
    }

    public function assertLearningArea(User $user, string $learningAreaId): void
    {
        $scope = $this->scope($user);
        $exists = DB::table('learning_areas')->where('id', $learningAreaId)->exists();
        if ($exists && Schema::hasColumn('learning_areas', 'school_id')) {
            $exists = DB::table('learning_areas')->where('id', $learningAreaId)->where('school_id', $scope['school_id'])->exists();
        }
        if (! $exists || ($scope['role_key'] === 'hod' && ! in_array($learningAreaId, $scope['learning_area_ids'], true))) {
            throw new AuthorizationException('Learning area is outside leadership scope.');
        }
    }

    private function normalizeRole(?string $role): string
    {
        return str_replace([' ', '-'], '_', strtolower(trim((string) $role)));
    }
}
