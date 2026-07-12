<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    private array $p = ['access_school_leadership_portal', 'view_leadership_dashboard', 'view_school_attendance_summary', 'view_school_curriculum_summary', 'view_school_academic_summary', 'view_school_discipline_summary', 'view_school_finance_summary', 'view_leadership_approvals', 'manage_leadership_approvals', 'view_teacher_workload', 'manage_leadership_dashboard_preferences'];

    public function up(): void
    {
        foreach ($this->p as $n) {
            DB::table('permissions')->insertOrIgnore(['id' => (string) Str::uuid(), 'permission_name' => $n, 'module_name' => 'Leadership Portal', 'description' => str_replace('_', ' ', $n), 'created_at' => now()]);
        }$grants = ['Principal' => $this->p, 'Deputy Principal' => array_diff($this->p, ['view_school_finance_summary']), 'HOD' => ['access_school_leadership_portal', 'view_leadership_dashboard', 'view_school_curriculum_summary', 'view_school_academic_summary', 'view_leadership_approvals', 'manage_leadership_approvals', 'view_teacher_workload', 'manage_leadership_dashboard_preferences'], 'Senior Teacher' => ['access_school_leadership_portal', 'view_leadership_dashboard', 'manage_leadership_dashboard_preferences'], 'Finance Officer' => ['access_school_leadership_portal', 'view_leadership_dashboard', 'view_school_finance_summary', 'manage_leadership_dashboard_preferences'], 'School Admin' => ['access_school_leadership_portal', 'view_leadership_dashboard', 'view_school_attendance_summary', 'view_school_academic_summary', 'view_leadership_approvals', 'view_teacher_workload', 'manage_leadership_dashboard_preferences']];
        foreach ($grants as $role => $names) {
            $r = DB::table('roles')->where('role_name', $role)->value('id');
            if (! $r) {
                continue;
            }foreach (DB::table('permissions')->whereIn('permission_name', $names)->pluck('id') as $p) {
                if (! DB::table('role_permissions')->where('role_id', $r)->where('permission_id', $p)->exists()) {
                    DB::table('role_permissions')->insert(['id' => (string) Str::uuid(), 'role_id' => $r, 'permission_id' => $p, 'created_at' => now()]);
                }
            }
        }
    }

    public function down(): void
    {
        $ids = DB::table('permissions')->whereIn('permission_name', $this->p)->pluck('id');
        DB::table('role_permissions')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();
    }
};
