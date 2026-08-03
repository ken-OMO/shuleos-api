<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    private const ALL = [
        'access_administrator_portal', 'access_platform_administration', 'view_admin_dashboard', 'view_platform_dashboard',
        'manage_school_profile', 'view_school_completeness', 'manage_school_lifecycle', 'view_school_users',
        'create_school_users', 'update_school_users', 'activate_school_users', 'suspend_school_users', 'unlock_school_users',
        'force_school_user_password_reset', 'revoke_school_user_sessions', 'revoke_school_user_devices',
        'view_roles_and_permissions', 'manage_school_roles', 'assign_school_permissions', 'view_academic_setup_status',
        'manage_school_branding', 'view_school_subscription', 'view_platform_subscriptions', 'view_module_readiness',
        'view_admin_audit', 'view_admin_security', 'manage_admin_security_actions', 'view_school_devices', 'revoke_school_devices',
        'manage_communication_policy', 'view_provider_readiness', 'view_payment_reconciliation_summary', 'manage_data_imports',
        'view_system_health', 'view_admin_tasks', 'view_admin_alerts', 'acknowledge_admin_alerts',
        'manage_admin_preferences', 'view_admin_reports', 'generate_admin_reports',
    ];

    public function up(): void
    {
        foreach (self::ALL as $name) {
            DB::table('permissions')->insertOrIgnore(['id' => (string) Str::uuid(), 'permission_name' => $name, 'module_name' => 'administrator_portal', 'description' => Str::headline($name), 'created_at' => now()]);
        }
        $platformOnly = ['access_platform_administration', 'view_platform_dashboard', 'manage_school_lifecycle', 'view_platform_subscriptions'];
        $school = array_values(array_diff(self::ALL, $platformOnly));
        $readOnly = ['access_administrator_portal', 'view_admin_dashboard', 'view_school_completeness', 'view_school_users', 'view_roles_and_permissions', 'view_academic_setup_status', 'view_school_subscription', 'view_module_readiness', 'view_admin_audit', 'view_admin_security', 'view_school_devices', 'view_provider_readiness', 'view_system_health', 'view_admin_tasks', 'view_admin_alerts', 'manage_admin_preferences', 'view_admin_reports'];
        $finance = ['access_administrator_portal', 'view_admin_dashboard', 'view_school_subscription', 'view_provider_readiness', 'view_payment_reconciliation_summary', 'view_admin_tasks', 'view_admin_alerts', 'view_admin_reports'];
        $grants = [
            'Platform Owner' => self::ALL,
            'Platform Super Administrator' => self::ALL,
            'School Admin' => $school,
            'Administrator' => $school,
            'Principal' => array_values(array_unique(array_merge($readOnly, ['manage_school_profile', 'update_school_users', 'activate_school_users', 'suspend_school_users', 'unlock_school_users', 'revoke_school_user_sessions', 'revoke_school_user_devices', 'manage_school_branding', 'manage_communication_policy']))),
            'Headteacher' => $readOnly,
            'Support Administrator' => $readOnly,
            'Finance Administrator' => $finance,
            'Finance Officer' => $finance,
        ];
        foreach ($grants as $roleName => $names) {
            foreach (DB::table('roles')->where('role_name', $roleName)->pluck('id') as $roleId) {
                foreach (DB::table('permissions')->whereIn('permission_name', $names)->pluck('id') as $permissionId) {
                    DB::table('role_permissions')->insertOrIgnore(['id' => (string) Str::uuid(), 'role_id' => $roleId, 'permission_id' => $permissionId, 'created_at' => now()]);
                }
            }
        }
    }

    public function down(): void {}
};
