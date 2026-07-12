<?php

namespace App\Services\LeadershipPortal;

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

class LeadershipPortalAccessService
{
    private const ROLES = ['Principal', 'Deputy Principal', 'HOD', 'Senior Teacher', 'School Admin', 'Finance Officer'];

    public function scope(User $u): array
    {
        abort_unless($u->active && $u->school_id, 403, 'Active school tenant required.');
        $role = $u->role?->role_name;
        if ($role === 'Platform Owner') {
            throw new AuthorizationException('Platform Owner must use platform administration scope.');
        }if (! in_array($role, self::ROLES, true)) {
            throw new AuthorizationException('School leadership role required.');
        }$areas = [];
        $teachers = [];
        if ($role === 'HOD') {
            $teacher = DB::table('teachers')->where('user_id', $u->id)->where('school_id', $u->school_id)->where('active', true)->where('is_deleted', false)->first();
            if (! $teacher) {
                throw new AuthorizationException('Active HOD teacher profile required.');
            }$areas = DB::table('hod_assignments')->where('school_id', $u->school_id)->where('teacher_id', $teacher->id)->where('active', true)->pluck('learning_area_id')->all();
            if (! $areas) {
                throw new AuthorizationException('No active HOD assignment exists.');
            }$teachers = DB::table('teacher_assignments')->where('school_id', $u->school_id)->whereIn('learning_area_id', $areas)->where('active', true)->where('is_deleted', false)->pluck('teacher_id')->unique()->all();
        }

        return ['school_id' => $u->school_id, 'role' => $role, 'whole_school' => in_array($role, ['Principal', 'Deputy Principal', 'School Admin', 'Finance Officer'], true), 'learning_area_ids' => $areas, 'teacher_ids' => $teachers, 'finance' => $this->has($u, 'view_school_finance_summary'), 'attendance' => $this->has($u, 'view_school_attendance_summary'), 'curriculum' => $this->has($u, 'view_school_curriculum_summary'), 'workload' => $this->has($u, 'view_teacher_workload'), 'discipline' => $this->has($u, 'view_school_discipline_summary'), 'academic' => $this->has($u, 'view_school_academic_summary'), 'approvals' => $this->has($u, 'view_leadership_approvals'), 'manage_approvals' => $this->has($u, 'manage_leadership_approvals')];
    }

    public function has(User $u, string $p): bool
    {
        return DB::table('role_permissions')->join('permissions', 'permissions.id', '=', 'role_permissions.permission_id')->where('role_permissions.role_id', $u->role_id)->where('permissions.permission_name', $p)->exists();
    }

    public function require(User $u, string $p): void
    {
        if (! $this->has($u, $p)) {
            throw new AuthorizationException('Permission denied.');
        }
    }
}
