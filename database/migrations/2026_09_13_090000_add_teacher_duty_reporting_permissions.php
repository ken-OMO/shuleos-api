<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    private const SUBMIT_PERMISSION = 'submit_teacher_duty_reports';

    private const REVIEW_PERMISSION = 'review_teacher_duty_reports';

    private const SUBMIT_ROLES = [
        'Teacher',
    ];

    private const REVIEW_ROLES = [
        'Principal',
        'Deputy Headteacher',
        'School Admin',
        'Administrator',
    ];

    public function up(): void
    {
        $permissions = [
            self::SUBMIT_PERMISSION => 'Submit Teacher Duty Reports',
            self::REVIEW_PERMISSION => 'Review Teacher Duty Reports',
        ];

        foreach ($permissions as $permissionName => $description) {
            DB::table('permissions')->insertOrIgnore([
                'id' => (string) Str::uuid(),
                'permission_name' => $permissionName,
                'module_name' => 'teacher_duty',
                'description' => $description,
                'created_at' => now(),
            ]);
        }

        $permissionIds = DB::table('permissions')
            ->whereIn(
                'permission_name',
                array_keys($permissions)
            )
            ->pluck(
                'id',
                'permission_name'
            );

        foreach (array_keys($permissions) as $permissionName) {
            if (! $permissionIds->has($permissionName)) {
                throw new RuntimeException(
                    "Teacher duty permission [{$permissionName}] could not be resolved."
                );
            }
        }

        $this->grantToSystemRoles(
            self::SUBMIT_PERMISSION,
            self::SUBMIT_ROLES,
            $permissionIds
        );

        $this->grantToSystemRoles(
            self::REVIEW_PERMISSION,
            self::REVIEW_ROLES,
            $permissionIds
        );
    }

    public function down(): void
    {
        /*
         * Intentionally non-destructive.
         *
         * These permissions may already be referenced by role assignments
         * after deployment. Removing them during rollback could silently
         * alter the Teacher Duty authorization boundary.
         */
    }

    private function grantToSystemRoles(
        string $permissionName,
        array $requiredRoleNames,
        $permissionIds
    ): void {
        $roles = DB::table('roles')
            ->whereIn(
                'role_name',
                $requiredRoleNames
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

        $expectedRoleNames = collect($requiredRoleNames)
            ->sort()
            ->values()
            ->all();

        if ($resolvedRoleNames !== $expectedRoleNames) {
            throw new RuntimeException(
                "Required Teacher duty system roles for [{$permissionName}] could not be resolved."
            );
        }

        foreach ($roles as $role) {
            DB::table('role_permissions')->insertOrIgnore([
                'id' => (string) Str::uuid(),
                'role_id' => $role->id,
                'permission_id' => $permissionIds->get($permissionName),
                'created_at' => now(),
            ]);
        }
    }
};
