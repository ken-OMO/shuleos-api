<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    private array $permissions = [
        'access_learner_portal_phase_two', 'submit_own_homework', 'edit_own_homework_drafts',
        'withdraw_own_homework_submission', 'resubmit_own_homework', 'view_own_submission_history',
        'upload_learner_portal_files', 'download_learner_portal_files', 'manage_own_offline_resources',
        'use_learner_offline_sync', 'resolve_own_learner_sync_conflicts', 'manage_own_learner_devices',
        'manage_own_learner_push_token', 'view_own_push_delivery_status', 'view_own_academic_progress',
        'create_learner_help_requests', 'view_own_help_requests', 'update_own_learner_profile',
        'manage_own_learner_preferences', 'view_own_learner_analytics',
    ];

    public function up(): void
    {
        foreach ($this->permissions as $name) {
            if (! DB::table('permissions')->where('permission_name', $name)->exists()) {
                DB::table('permissions')->insert(['id' => (string) Str::uuid(), 'permission_name' => $name]);
            }
        }
        $role = DB::table('roles')->whereRaw('LOWER(role_name) = ?', ['learner'])->value('id');
        if (! $role) {
            return;
        }
        foreach (DB::table('permissions')->whereIn('permission_name', $this->permissions)->pluck('id') as $permission) {
            if (! DB::table('role_permissions')->where('role_id', $role)->where('permission_id', $permission)->exists()) {
                DB::table('role_permissions')->insert(['id' => (string) Str::uuid(), 'role_id' => $role, 'permission_id' => $permission]);
            }
        }
    }

    public function down(): void
    {
        $ids = DB::table('permissions')->whereIn('permission_name', $this->permissions)->pluck('id');
        DB::table('role_permissions')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();
    }
};
