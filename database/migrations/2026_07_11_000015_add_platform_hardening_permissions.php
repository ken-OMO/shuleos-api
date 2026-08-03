<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['manage_academics', 'manage_attendance', 'manage_timetable'] as $name) {
            if (! DB::table('permissions')->where('permission_name', $name)->exists()) {
                DB::table('permissions')->insert([
                    'id' => (string) Str::uuid(),
                    'permission_name' => $name,
                ]);
            }
        }

        $administratorRoleIds = DB::table('roles')
            ->whereIn('role_name', ['Platform Owner', 'School Admin'])
            ->pluck('id');

        $permissionIds = DB::table('permissions')->pluck('id');

        foreach ($administratorRoleIds as $roleId) {
            foreach ($permissionIds as $permissionId) {
                DB::table('role_permissions')->updateOrInsert([
                    'role_id' => $roleId,
                    'permission_id' => $permissionId,
                ]);
            }
        }
    }

    public function down(): void
    {
        $ids = DB::table('permissions')
            ->whereIn('permission_name', ['manage_academics', 'manage_attendance', 'manage_timetable'])
            ->pluck('id');

        DB::table('role_permissions')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();
    }
};
