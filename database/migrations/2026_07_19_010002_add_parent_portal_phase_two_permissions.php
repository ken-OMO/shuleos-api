<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    private const PERMISSIONS = [
        'access_parent_portal_phase_two', 'initiate_linked_learner_payments', 'view_own_payment_attempts',
        'download_own_payment_receipts', 'create_parent_conversations', 'send_parent_messages',
        'view_own_parent_conversations', 'respond_to_parent_consents', 'view_linked_learner_consents',
        'create_parent_appointments', 'manage_own_parent_appointments', 'view_linked_learner_progress',
        'view_own_parent_tasks', 'use_parent_offline_sync', 'resolve_own_parent_sync_conflicts',
        'upload_parent_portal_files', 'download_parent_portal_files', 'manage_own_parent_push_token',
        'view_own_parent_push_deliveries', 'view_own_parent_analytics',
    ];

    public function up(): void
    {
        foreach (self::PERMISSIONS as $name) {
            DB::table('permissions')->insertOrIgnore(['id' => (string) Str::uuid(), 'permission_name' => $name, 'module_name' => 'parent_portal_phase_two', 'description' => Str::headline($name), 'created_at' => now()]);
        }
        $permissions = DB::table('permissions')->whereIn('permission_name', self::PERMISSIONS)->pluck('id');
        foreach (DB::table('roles')->whereRaw('LOWER(role_name) = ?', ['parent'])->pluck('id') as $roleId) {
            foreach ($permissions as $permissionId) {
                DB::table('role_permissions')->insertOrIgnore(['id' => (string) Str::uuid(), 'role_id' => $roleId, 'permission_id' => $permissionId, 'created_at' => now()]);
            }
        }
    }

    public function down(): void {}
};
