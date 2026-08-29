<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        $permissionId = DB::table('permissions')
            ->where('permission_name', 'manage_guardians')
            ->value('id');

        if (! $permissionId) {
            $permissionId = (string) Str::uuid();

            DB::table('permissions')->insert([
                'id' => $permissionId,
                'permission_name' => 'manage_guardians',
                'module_name' => 'administrator_portal',
                'description' => 'Manage Guardians',
                'created_at' => now(),
            ]);
        }

        $roleIds = DB::table('roles')
            ->whereIn('role_name', [
                'School Admin',
                'Administrator',
            ])
            ->whereNull('school_id')
            ->where('system_role', true)
            ->where('active', true)
            ->pluck('id');

        if ($roleIds->count() !== 2) {
            throw new RuntimeException(
                'Unable to resolve required system roles for manage_guardians.'
            );
        }

        foreach ($roleIds as $roleId) {
            DB::table('role_permissions')->insertOrIgnore([
                'role_id' => $roleId,
                'permission_id' => $permissionId,
            ]);
        }
    }

    public function down(): void
    {
        // Intentionally non-destructive.
        //
        // Removing a security permission during rollback could alter
        // authorization semantics for already-provisioned installations.
    }
};
