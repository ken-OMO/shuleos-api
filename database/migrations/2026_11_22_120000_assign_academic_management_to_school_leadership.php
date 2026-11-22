<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        $permissionId = DB::table('permissions')
            ->where('permission_name', 'manage_academics')
            ->soleValue('id');

        $systemRoles = DB::table('roles')
            ->whereNull('school_id')
            ->where('system_role', true);

        foreach ((clone $systemRoles)->whereIn('role_name', ['Headteacher', 'Principal'])->pluck('id') as $roleId) {
            DB::table('role_permissions')->insertOrIgnore([
                'id' => (string) Str::uuid(),
                'role_id' => $roleId,
                'permission_id' => $permissionId,
                'created_at' => now(),
            ]);
        }

        DB::table('role_permissions')
            ->where('permission_id', $permissionId)
            ->whereIn('role_id', (clone $systemRoles)->where('role_name', 'School Admin')->select('id'))
            ->delete();
    }

    public function down(): void
    {
        throw new RuntimeException('Academic role grants require a reviewed forward migration.');
    }
};
