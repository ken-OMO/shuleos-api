<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    // Deliberately limited to portal entry and the read positive control that
    // failed live verification. Do not replay historical bulk permission grants.
    private const GRANTS = [
        'Teacher' => ['view_own_teacher_assignments'],
        'Principal' => [
            'access_school_leadership_portal',
            'access_leadership_portal_phase_two',
            'view_principal_dashboard',
            'view_school_kpis',
            'view_leadership_action_queue',
            'view_leadership_alerts',
        ],
        'Headteacher' => [
            'access_school_leadership_portal',
            'access_leadership_portal_phase_two',
            'view_principal_dashboard',
            'view_school_kpis',
            'view_leadership_action_queue',
            'view_leadership_alerts',
        ],
        'Parent' => ['access_parent_portal_phase_two'],
        'Learner' => ['access_learner_portal_phase_two'],
    ];

    public function up(): void
    {
        DB::transaction(function (): void {
            $roles = DB::table('roles')
                ->whereIn('role_name', array_keys(self::GRANTS))
                ->whereNull('school_id')
                ->where('system_role', true)
                ->where('active', true)
                ->orderBy('id')
                ->lockForUpdate()
                ->get(['id', 'role_name']);

            $names = $roles->flatMap(fn ($role) => self::GRANTS[$role->role_name])
                ->unique()->values();
            $permissions = DB::table('permissions')
                ->whereIn('permission_name', $names)
                ->pluck('id', 'permission_name');
            $missing = $names->diff($permissions->keys())->values();

            // Resolve the complete catalogue before writing any grants.
            if ($missing->isNotEmpty()) {
                throw new RuntimeException('Missing portal permissions: '.$missing->implode(', '));
            }

            foreach ($roles as $role) {
                foreach (self::GRANTS[$role->role_name] as $name) {
                    $permissionId = $permissions[$name];
                    if (DB::table('role_permissions')
                        ->where('role_id', $role->id)
                        ->where('permission_id', $permissionId)
                        ->exists()) {
                        continue;
                    }

                    DB::table('role_permissions')->insert([
                        'id' => (string) Str::uuid(),
                        'role_id' => $role->id,
                        'permission_id' => $permissionId,
                        'created_at' => now(),
                    ]);
                }
            }
        });
    }

    public function down(): void
    {
        // There is no provenance flag distinguishing a pre-existing grant from
        // one introduced here. An inverse delete could revoke legitimate access.
        throw new RuntimeException('Portal entry permission backfill is forward-only; use a reviewed corrective migration.');
    }
};
