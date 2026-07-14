<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    private array $management = ['create_timetable', 'edit_timetable', 'validate_timetable', 'approve_timetable', 'publish_timetable', 'archive_timetable', 'manage_timetable_profiles', 'manage_timetable_periods', 'manage_timetable_constraints', 'manage_rooms', 'manage_timetable_substitutions', 'view_timetable_analytics', 'view_school_timetable'];

    public function up(): void
    {
        $all = array_merge($this->management, ['view_own_timetable', 'view_linked_learner_timetable']);
        foreach (array_unique($all) as $name) {
            DB::table('permissions')->insertOrIgnore(['id' => (string) Str::uuid(), 'permission_name' => $name, 'module_name' => 'Timetable', 'description' => str_replace('_', ' ', $name), 'created_at' => now()]);
        }
        $grants = ['Principal' => $this->management, 'Deputy Principal' => $this->management, 'School Admin' => $this->management, 'HOD' => ['view_school_timetable', 'view_timetable_analytics', 'manage_timetable_substitutions'], 'Teacher' => ['view_own_timetable'], 'Learner' => ['view_own_timetable'], 'Parent' => ['view_linked_learner_timetable']];
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
