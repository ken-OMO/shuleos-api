<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    private array $teacher = ['report_behaviour_cases', 'view_assigned_learner_behaviour', 'assign_basic_behaviour_actions', 'award_behaviour_recognition'];

    private array $learner = ['view_own_behaviour', 'view_own_recognitions'];

    private array $parent = ['view_linked_learner_behaviour', 'view_linked_learner_recognitions'];

    private array $leadership = ['manage_discipline_categories', 'review_behaviour_cases', 'resolve_behaviour_cases', 'assign_restricted_behaviour_actions', 'approve_behaviour_recognitions', 'view_behaviour_analytics', 'manage_counselling_referrals', 'view_attendance_risk_flags', 'resolve_attendance_risk_flags', 'correct_finalized_attendance'];

    public function up(): void
    {
        foreach (array_merge($this->teacher, $this->learner, $this->parent, $this->leadership) as $n) {
            DB::table('permissions')->insertOrIgnore(['id' => (string) Str::uuid(), 'permission_name' => $n, 'module_name' => 'Behaviour', 'description' => str_replace('_', ' ', $n), 'created_at' => now()]);
        }foreach (['Teacher' => $this->teacher, 'Learner' => $this->learner, 'Parent' => $this->parent, 'HOD' => ['review_behaviour_cases', 'view_behaviour_analytics', 'view_attendance_risk_flags'], 'Principal' => $this->leadership, 'Deputy Principal' => $this->leadership, 'School Admin' => $this->leadership] as $role => $names) {
            $id = DB::table('roles')->where('role_name', $role)->value('id');
            if (! $id) {
                continue;
            }foreach (DB::table('permissions')->whereIn('permission_name', $names)->pluck('id') as $p) {
                DB::table('role_permissions')->insertOrIgnore(['id' => (string) Str::uuid(), 'role_id' => $id, 'permission_id' => $p, 'created_at' => now()]);
            }
        }
    }

    public function down(): void
    {
        $ids = DB::table('permissions')->whereIn('permission_name', array_merge($this->teacher, $this->learner, $this->parent, $this->leadership))->pluck('id');
        DB::table('role_permissions')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();
    }
};
