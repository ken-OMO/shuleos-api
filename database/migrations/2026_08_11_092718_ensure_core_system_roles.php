<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    private const SYSTEM_ROLES = [
        'Platform Owner',
        'Platform Super Administrator',

        'School Admin',
        'Administrator',

        'Principal',
        'Headteacher',
        'Deputy Headteacher',
        'HOD',

        'Teacher',

        'Support Administrator',

        'Finance Administrator',
        'Finance Officer',

        'Learner',

        'Parent',
        'Guardian',
    ];

    public function up(): void
    {
        foreach (self::SYSTEM_ROLES as $roleName) {
            $existing = DB::table('roles')
                ->where('role_name', $roleName)
                ->whereNull('school_id')
                ->first();

            if ($existing) {
                DB::table('roles')
                    ->where('id', $existing->id)
                    ->update([
                        'system_role' => true,
                        'active' => true,
                        'updated_at' => now(),
                    ]);

                continue;
            }

            DB::table('roles')->insert([
                'id' => (string) Str::uuid(),
                'role_name' => $roleName,
                'school_id' => null,
                'system_role' => true,
                'active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        /*
         * Deliberately do not delete system roles.
         *
         * Roles may already be referenced by users, user_roles and
         * role_permissions. Destructive rollback would be unsafe.
         */
    }
};
