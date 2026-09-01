<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    private const PERMISSION = 'manage_boarding';

    /**
     * Boarding authority is initially attached only to the existing
     * high-trust school administration system roles.
     *
     * Dedicated school Boarding roles may later receive this permission
     * through the normal delegated/custom-role authorization workflow.
     */
    private const ROLES = [
        'School Admin',
        'Administrator',
    ];

    public function up(): void
    {
        DB::table('permissions')->insertOrIgnore([
            'id' => (string) Str::uuid(),
            'permission_name' => self::PERMISSION,
            'module_name' => 'boarding',
            'description' => 'Manage Boarding',
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
                'Boarding management permission could not be resolved.'
            );
        }

        $roles = DB::table('roles')
            ->whereIn(
                'role_name',
                self::ROLES
            )
            ->whereNull('school_id')
            ->where('system_role', true)
            ->where('active', true)
            ->get([
                'id',
                'role_name',
            ]);

        $resolvedRoleNames = $roles
            ->pluck('role_name')
            ->sort()
            ->values()
            ->all();

        $requiredRoleNames = collect(self::ROLES)
            ->sort()
            ->values()
            ->all();

        if ($resolvedRoleNames !== $requiredRoleNames) {
            throw new RuntimeException(
                'Required Boarding management system roles could not be resolved.'
            );
        }

        foreach ($roles as $role) {
            DB::table('role_permissions')->insertOrIgnore([
                'id' => (string) Str::uuid(),
                'role_id' => $role->id,
                'permission_id' => $permissionId,
                'created_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        /*
         * Intentionally non-destructive.
         *
         * The permission may already be referenced by role assignments
         * after deployment. Removing it during rollback could silently
         * weaken or alter the Boarding authorization boundary.
         */
    }
};
