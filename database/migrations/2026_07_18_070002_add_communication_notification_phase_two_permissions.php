<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        $permissions = ['configure_email_provider', 'configure_sms_provider', 'manage_sms_wallet', 'adjust_sms_credits', 'view_sms_billing', 'send_sms_communications', 'send_finance_sms', 'send_critical_attendance_sms', 'send_emergency_broadcasts', 'manage_recurring_communications', 'manage_communication_preferences', 'view_provider_delivery_diagnostics', 'view_advanced_communication_analytics', 'manage_email_suppressions', 'manage_contact_health', 'manage_communication_branding'];
        foreach ($permissions as $permission) {
            DB::table('permissions')->insertOrIgnore(['id' => (string) Str::uuid(), 'permission_name' => $permission, 'module_name' => 'Communication', 'description' => str_replace('_', ' ', $permission), 'created_at' => now()]);
        }

        $leadership = $permissions;
        $finance = ['view_sms_billing', 'send_sms_communications', 'send_finance_sms'];
        $attendance = ['send_sms_communications', 'send_critical_attendance_sms'];
        $grants = [
            'Principal' => $leadership,
            'School Admin' => $leadership,
            'Deputy Principal' => array_values(array_diff($leadership, ['adjust_sms_credits', 'configure_email_provider', 'configure_sms_provider'])),
            'Finance Officer' => $finance,
            'Bursar' => $finance,
            'Senior Teacher' => $attendance,
        ];
        foreach ($grants as $role => $rolePermissions) {
            $roleId = DB::table('roles')->where('role_name', $role)->value('id');
            if (! $roleId) {
                continue;
            }
            foreach (DB::table('permissions')->whereIn('permission_name', $rolePermissions)->pluck('id') as $permissionId) {
                DB::table('role_permissions')->insertOrIgnore(['id' => (string) Str::uuid(), 'role_id' => $roleId, 'permission_id' => $permissionId, 'created_at' => now()]);
            }
        }
    }

    public function down(): void {}
};
