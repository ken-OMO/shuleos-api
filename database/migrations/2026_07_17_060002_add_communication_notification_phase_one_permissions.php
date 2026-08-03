<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        $creator = ['create_communications', 'edit_own_communication_drafts', 'preview_communication_recipients', 'send_own_class_communications', 'send_assigned_class_communications', 'send_parent_communications', 'send_learner_communications', 'view_communication_history', 'view_own_notifications'];
        $leadership = ['view_detailed_recipient_preview', 'send_grade_communications', 'send_schoolwide_communications', 'send_staff_communications', 'send_emergency_communications', 'approve_communications', 'schedule_communications', 'cancel_communications', 'view_communication_analytics', 'manage_communication_templates', 'manage_communication_policies'];
        $specialist = ['send_finance_communications', 'send_attendance_communications'];
        $portal = ['view_linked_learner_communications'];
        foreach (array_unique(array_merge($creator, $leadership, $specialist, $portal)) as $permission) {
            DB::table('permissions')->insertOrIgnore(['id' => (string) Str::uuid(), 'permission_name' => $permission, 'module_name' => 'Communication', 'description' => str_replace('_', ' ', $permission), 'created_at' => now()]);
        }
        $grants = [
            'Teacher' => $creator,
            'Senior Teacher' => array_merge($creator, ['send_grade_communications']),
            'School Admin' => array_merge($creator, $leadership, $specialist),
            'Principal' => array_merge($creator, $leadership, $specialist),
            'Deputy Principal' => array_merge($creator, $leadership, ['send_attendance_communications']),
            'Director' => array_merge($leadership, ['view_communication_history']),
            'Finance Officer' => array_merge($creator, ['send_finance_communications']),
            'Bursar' => array_merge($creator, ['send_finance_communications']),
            'Learner' => ['view_own_notifications'],
            'Parent' => ['view_own_notifications', 'view_linked_learner_communications'],
        ];
        foreach ($grants as $role => $permissions) {
            $roleId = DB::table('roles')->where('role_name', $role)->value('id');
            if (! $roleId) {
                continue;
            }
            foreach (DB::table('permissions')->whereIn('permission_name', $permissions)->pluck('id') as $permissionId) {
                DB::table('role_permissions')->insertOrIgnore(['id' => (string) Str::uuid(), 'role_id' => $roleId, 'permission_id' => $permissionId, 'created_at' => now()]);
            }
        }
    }

    public function down(): void {}
};
