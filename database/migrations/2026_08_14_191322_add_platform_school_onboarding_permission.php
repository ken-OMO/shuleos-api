<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    private const PERMISSION = 'onboard_schools';

    public function up(): void
    {
        DB::table('permissions')->insertOrIgnore([
            'id' => (string) Str::uuid(),
            'permission_name' => self::PERMISSION,
            'module_name' => 'administrator_portal',
            'description' => 'Onboard Schools',
            'created_at' => now(),
        ]);

        $permissionId = DB::table('permissions')
            ->where(
                'permission_name',
                self::PERMISSION
            )
            ->value('id');

        if (! $permissionId) {
            throw new RuntimeException(
                'School onboarding permission could not be resolved.'
            );
        }

        $platformRoleIds = DB::table('roles')
            ->whereIn(
                'role_name',
                [
                    'Platform Owner',
                    'Platform Super Administrator',
                ]
            )
            ->whereNull('school_id')
            ->where('system_role', true)
            ->where('active', true)
            ->pluck('id');

        foreach ($platformRoleIds as $roleId) {
            DB::table('role_permissions')
                ->insertOrIgnore([
                    'id' => (string) Str::uuid(),
                    'role_id' => $roleId,
                    'permission_id' => $permissionId,
                    'created_at' => now(),
                ]);
        }
    }

    public function down(): void
    {
        $permissionIds = DB::table('permissions')
            ->where(
                'permission_name',
                self::PERMISSION
            )
            ->pluck('id');

        DB::table('role_permissions')
            ->whereIn(
                'permission_id',
                $permissionIds
            )
            ->delete();

        DB::table('permissions')
            ->whereIn(
                'id',
                $permissionIds
            )
            ->delete();
    }
};
