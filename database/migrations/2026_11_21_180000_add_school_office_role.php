<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    private const OFFICE = ['access_school_office', 'view_school_office_summary'];

    private const HEADTEACHER = [
        'access_school_leadership_portal', 'view_leadership_dashboard',
        'view_school_attendance_summary', 'view_school_curriculum_summary', 'view_school_academic_summary',
        'view_school_discipline_summary', 'view_leadership_approvals', 'manage_leadership_approvals',
        'manage_leadership_dashboard_preferences', 'view_hod_department_analytics', 'review_teacher_duty_reports',
        'access_leadership_portal_phase_two', 'view_principal_dashboard', 'view_school_kpis',
        'view_teacher_compliance', 'view_teacher_workload', 'view_academic_insights',
        'view_attendance_intelligence', 'view_behaviour_oversight', 'view_communication_monitoring',
        'view_timetable_oversight', 'view_leadership_action_queue', 'view_leadership_alerts',
        'acknowledge_leadership_alerts', 'manage_leadership_preferences', 'manage_leadership_devices',
        'view_leadership_reports', 'generate_leadership_reports', 'review_cross_module_approvals',
    ];

    // Snapshot of the reviewed administration permissions. Never clone arbitrary grants.
    private const DIRECTOR_PERMISSIONS = [
        'access_administrator_portal', 'view_admin_dashboard', 'manage_school_profile',
        'view_school_completeness', 'view_school_users', 'create_school_users', 'update_school_users',
        'activate_school_users', 'suspend_school_users', 'unlock_school_users',
        'force_school_user_password_reset', 'revoke_school_user_sessions', 'revoke_school_user_devices',
        'view_roles_and_permissions', 'manage_school_roles', 'assign_school_permissions',
        'view_academic_setup_status', 'manage_school_branding', 'view_school_subscription',
        'view_module_readiness', 'view_admin_audit', 'view_admin_security', 'manage_admin_security_actions',
        'view_school_devices', 'revoke_school_devices', 'manage_communication_policy',
        'view_provider_readiness', 'view_payment_reconciliation_summary', 'manage_data_imports',
        'view_system_health', 'view_admin_tasks', 'view_admin_alerts', 'acknowledge_admin_alerts',
        'manage_admin_preferences', 'view_admin_reports', 'generate_admin_reports',
    ];

    public function up(): void
    {
        foreach (self::OFFICE as $name) {
            DB::table('permissions')->insertOrIgnore(['id' => (string) Str::uuid(), 'permission_name' => $name,
                'module_name' => 'school_office', 'description' => Str::headline($name), 'created_at' => now()]);
        }
        $role = DB::table('roles')->whereNull('school_id')->where('role_name', 'School Secretary')->first();
        if ($role && ! $role->system_role) {
            throw new RuntimeException('A non-system Secretary role requires manual review.');
        }
        if (! $role) {
            $id = (string) Str::uuid();
            DB::table('roles')->insert(['id' => $id, 'role_name' => 'School Secretary', 'school_id' => null, 'system_role' => true, 'active' => true]);
            $role = DB::table('roles')->where('id', $id)->first();
        }
        foreach (DB::table('permissions')->whereIn('permission_name', self::OFFICE)->pluck('id') as $permissionId) {
            DB::table('role_permissions')->insertOrIgnore(['id' => (string) Str::uuid(), 'role_id' => $role->id,
                'permission_id' => $permissionId, 'created_at' => now()]);
        }
        $headteacher = DB::table('roles')->whereNull('school_id')->where('system_role', true)->where('role_name', 'Headteacher')->value('id');
        foreach (self::HEADTEACHER as $name) {
            DB::table('permissions')->insertOrIgnore(['id' => (string) Str::uuid(), 'permission_name' => $name,
                'module_name' => 'leadership_portal', 'description' => Str::headline($name), 'created_at' => now()]);
            if ($headteacher) {
                DB::table('role_permissions')->insertOrIgnore(['id' => (string) Str::uuid(), 'role_id' => $headteacher,
                    'permission_id' => DB::table('permissions')->where('permission_name', $name)->value('id'), 'created_at' => now()]);
            }
        }
        // Directors use the administration workspace. Do not copy platform authority.
        $admin = DB::table('roles')->whereNull('school_id')->where('system_role', true)->where('role_name', 'School Admin')->value('id');
        if ($admin) {
            foreach (DB::table('roles')->whereNull('school_id')->where('system_role', true)->whereIn('role_name', ['Director', 'School Owner'])->pluck('id') as $id) {
                foreach (DB::table('role_permissions')->join('permissions', 'permissions.id', '=', 'role_permissions.permission_id')
                    ->where('role_id', $admin)->whereIn('permissions.permission_name', self::DIRECTOR_PERMISSIONS)->pluck('permission_id') as $permissionId) {
                    DB::table('role_permissions')->insertOrIgnore(['id' => (string) Str::uuid(), 'role_id' => $id, 'permission_id' => $permissionId, 'created_at' => now()]);
                }
            }
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Staff access changes require a reviewed forward migration.');
    }
};
