<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        $permissions = ['generate_timetable', 'repair_timetable', 'rebalance_timetable', 'lock_timetable_entries', 'create_timetable_versions', 'view_timetable_generation_runs', 'approve_timetable_substitutions', 'unpublish_timetable', 'supersede_timetable'];
        foreach ($permissions as $name) {
            DB::table('permissions')->insertOrIgnore(['id' => (string) Str::uuid(), 'permission_name' => $name, 'module_name' => 'Timetable', 'description' => str_replace('_', ' ', $name), 'created_at' => now()]);
        }
        foreach (['Principal', 'Deputy Principal', 'School Admin'] as $role) {
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
