
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    private const PERMISSION = 'manage_learners';

    private const ROLES = [
        'School Admin',
        'Administrator',
    ];

    public function up(): void
    {
        DB::table('permissions')->insertOrIgnore([
            'id' => (string) Str::uuid(),
            'permission_name' => self::PERMISSION,
            'module_name' => 'administrator_portal',
            'description' => 'Manage Learners',
            'created_at' => now(),
        ]);

        $permissionId = DB::table('permissions')
            ->where('permission_name', self::PERMISSION)
            ->value('id');

        if (! $permissionId) {
            throw new RuntimeException(
                'Learner management permission could not be resolved.'
            );
        }

        $roleIds = DB::table('roles')
            ->whereIn('role_name', self::ROLES)
            ->whereNull('school_id')
            ->where('system_role', true)
            ->where('active', true)
            ->pluck('id');

        if ($roleIds->count() !== count(self::ROLES)) {
            throw new RuntimeException(
                'Required learner management system roles could not be resolved.'
            );
        }

        foreach ($roleIds as $roleId) {
            DB::table('role_permissions')->insertOrIgnore([
                'id' => (string) Str::uuid(),
                'role_id' => $roleId,
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
         * This permission may be referenced by active role assignments
         * after deployment. Removing it during rollback could silently
         * change the authorization boundary for learner administration.
         */
    }
};
