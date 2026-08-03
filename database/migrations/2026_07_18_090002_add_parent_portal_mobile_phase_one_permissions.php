<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    private const PERMISSIONS = [
        'access_parent_portal',
        'view_linked_learners',
        'view_linked_learner_profile',
        'view_linked_learner_attendance',
        'view_linked_learner_timetable',
        'view_linked_learner_homework',
        'view_linked_learner_learning_resources',
        'view_linked_learner_results',
        'view_linked_learner_report_cards',
        'download_linked_learner_report_cards',
        'view_linked_learner_finance',
        'view_parent_communications',
        'view_parent_announcements',
        'view_parent_calendar',
        'view_parent_documents',
        'update_own_parent_profile',
        'manage_own_parent_preferences',
        'manage_own_parent_devices',
    ];

    public function up(): void
    {
        foreach (self::PERMISSIONS as $name) {
            DB::table('permissions')->insertOrIgnore([
                'id' => (string) Str::uuid(),
                'permission_name' => $name,
                'module_name' => 'parent_portal',
                'description' => Str::headline($name),
                'created_at' => now(),
            ]);
        }

        $parentRoles = DB::table('roles')->whereRaw('LOWER(role_name) = ?', ['parent'])->pluck('id');
        $permissions = DB::table('permissions')->whereIn('permission_name', self::PERMISSIONS)->pluck('id');
        foreach ($parentRoles as $roleId) {
            foreach ($permissions as $permissionId) {
                DB::table('role_permissions')->insertOrIgnore([
                    'id' => (string) Str::uuid(),
                    'role_id' => $roleId,
                    'permission_id' => $permissionId,
                    'created_at' => now(),
                ]);
            }
        }
    }

    public function down(): void {}
};
