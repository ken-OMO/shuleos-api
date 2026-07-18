<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    private array $permissions = [
        'access_leadership_portal_phase_two', 'view_principal_dashboard', 'view_deputy_dashboard',
        'view_hod_dashboard', 'view_director_dashboard', 'view_school_kpis', 'view_teacher_compliance',
        'view_teacher_workload', 'view_academic_insights', 'view_attendance_intelligence',
        'view_behaviour_oversight', 'view_finance_oversight', 'view_communication_monitoring',
        'view_timetable_oversight', 'view_leadership_action_queue', 'view_leadership_alerts',
        'acknowledge_leadership_alerts', 'manage_leadership_preferences', 'manage_leadership_devices',
        'view_hod_department_analytics', 'view_leadership_reports', 'generate_leadership_reports',
        'review_cross_module_approvals',
    ];

    public function up(): void
    {
        foreach ($this->permissions as $name) {
            if (! DB::table('permissions')->where('permission_name', $name)->exists()) {
                DB::table('permissions')->insert(['id' => (string) Str::uuid(), 'permission_name' => $name]);
            }
        }

        $all = $this->permissions;
        $withoutFinance = array_values(array_diff($all, ['view_finance_oversight']));
        $hod = [
            'access_leadership_portal_phase_two', 'view_hod_dashboard', 'view_teacher_compliance',
            'view_teacher_workload', 'view_academic_insights', 'view_attendance_intelligence',
            'view_communication_monitoring', 'view_timetable_oversight', 'view_leadership_action_queue',
            'view_leadership_alerts', 'acknowledge_leadership_alerts', 'manage_leadership_preferences',
            'manage_leadership_devices', 'view_hod_department_analytics', 'view_leadership_reports',
            'generate_leadership_reports', 'review_cross_module_approvals',
        ];
        $grants = [
            'Principal' => $all,
            'Headteacher' => $all,
            'Deputy Principal' => $withoutFinance,
            'Deputy Headteacher' => $withoutFinance,
            'Director' => array_values(array_diff($all, ['review_cross_module_approvals', 'acknowledge_leadership_alerts'])),
            'School Owner' => array_values(array_diff($all, ['review_cross_module_approvals', 'acknowledge_leadership_alerts'])),
            'HOD' => $hod,
            'School Admin' => $withoutFinance,
            'Finance Officer' => ['access_leadership_portal_phase_two', 'view_director_dashboard', 'view_school_kpis', 'view_finance_oversight', 'view_leadership_action_queue', 'view_leadership_alerts', 'manage_leadership_preferences', 'manage_leadership_devices', 'view_leadership_reports', 'generate_leadership_reports'],
            'Finance Manager' => ['access_leadership_portal_phase_two', 'view_director_dashboard', 'view_school_kpis', 'view_finance_oversight', 'view_leadership_action_queue', 'view_leadership_alerts', 'manage_leadership_preferences', 'manage_leadership_devices', 'view_leadership_reports', 'generate_leadership_reports'],
            'Examination Officer' => ['access_leadership_portal_phase_two', 'view_deputy_dashboard', 'view_school_kpis', 'view_teacher_compliance', 'view_academic_insights', 'view_leadership_action_queue', 'view_leadership_alerts', 'manage_leadership_preferences', 'manage_leadership_devices', 'view_leadership_reports', 'generate_leadership_reports', 'review_cross_module_approvals'],
            'Discipline Lead' => ['access_leadership_portal_phase_two', 'view_deputy_dashboard', 'view_attendance_intelligence', 'view_behaviour_oversight', 'view_leadership_action_queue', 'view_leadership_alerts', 'acknowledge_leadership_alerts', 'manage_leadership_preferences', 'manage_leadership_devices', 'view_leadership_reports'],
            'Timetable Manager' => ['access_leadership_portal_phase_two', 'view_deputy_dashboard', 'view_timetable_oversight', 'view_leadership_action_queue', 'view_leadership_alerts', 'acknowledge_leadership_alerts', 'manage_leadership_preferences', 'manage_leadership_devices', 'view_leadership_reports'],
        ];

        foreach ($grants as $roleName => $names) {
            $roleId = DB::table('roles')->where('role_name', $roleName)->value('id');
            if (! $roleId) {
                continue;
            }
            foreach (DB::table('permissions')->whereIn('permission_name', $names)->pluck('id') as $permissionId) {
                if (! DB::table('role_permissions')->where('role_id', $roleId)->where('permission_id', $permissionId)->exists()) {
                    DB::table('role_permissions')->insert(['id' => (string) Str::uuid(), 'role_id' => $roleId, 'permission_id' => $permissionId]);
                }
            }
        }
    }

    public function down(): void
    {
        // view_teacher_workload predates Phase 2 and must survive a rollback.
        $removable = array_values(array_diff($this->permissions, ['view_teacher_workload']));
        $ids = DB::table('permissions')->whereIn('permission_name', $removable)->pluck('id');
        DB::table('role_permissions')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();
    }
};
