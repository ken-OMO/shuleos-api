<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    private const ALL = ['access_administrator_operations', 'manage_school_feature_flags', 'manage_platform_feature_flags', 'manage_school_maintenance', 'manage_platform_maintenance', 'view_provider_configuration', 'manage_provider_configuration', 'rotate_provider_secrets', 'view_queue_operations', 'retry_failed_jobs', 'forget_failed_jobs', 'view_scheduler_operations', 'run_allowlisted_scheduler_tasks', 'view_cache_operations', 'clear_safe_cache_groups', 'view_application_logs', 'view_storage_operations', 'manage_quarantined_files', 'view_backup_operations', 'create_backups', 'verify_backups', 'archive_backups', 'view_restore_operations', 'create_restore_requests', 'execute_restore_operations', 'manage_api_keys', 'manage_webhooks', 'view_operational_diagnostics', 'run_operational_diagnostics', 'manage_system_notices', 'view_release_metadata', 'manage_platform_settings', 'view_disaster_recovery_readiness'];

    public function up(): void
    {
        foreach (self::ALL as $name) {
            DB::table('permissions')->insertOrIgnore(['id' => (string) Str::uuid(), 'permission_name' => $name, 'module_name' => 'administrator_operations', 'description' => Str::headline($name), 'created_at' => now()]);
        }
        $owner = self::ALL;
        $platform = array_values(array_diff(self::ALL, ['execute_restore_operations']));
        $school = ['access_administrator_operations', 'manage_school_feature_flags', 'manage_school_maintenance', 'view_provider_configuration', 'view_cache_operations', 'clear_safe_cache_groups', 'view_storage_operations', 'view_backup_operations', 'create_backups', 'verify_backups', 'archive_backups', 'manage_api_keys', 'manage_webhooks', 'view_operational_diagnostics', 'run_operational_diagnostics', 'view_release_metadata'];
        foreach (['Platform Owner' => $owner, 'Platform Super Administrator' => $platform, 'School Admin' => $school, 'Administrator' => $school] as $role => $permissions) {
            foreach (DB::table('roles')->where('role_name', $role)->pluck('id') as $roleId) {
                foreach (DB::table('permissions')->whereIn('permission_name', $permissions)->pluck('id') as $permissionId) {
                    DB::table('role_permissions')->insertOrIgnore(['id' => (string) Str::uuid(), 'role_id' => $roleId, 'permission_id' => $permissionId, 'created_at' => now()]);
                }
            }
        }
    }

    public function down(): void {}
};
