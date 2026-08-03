<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    private array $l = ['access_student_elections', 'view_student_elections', 'vote_in_student_elections', 'view_published_election_results', 'view_student_leaders'];

    private array $m = ['manage_student_leadership_positions', 'manage_student_elections', 'manage_election_candidates', 'manage_election_voters', 'tally_student_elections', 'publish_student_election_results', 'manage_student_leadership_terms', 'view_student_election_audit_logs'];

    public function up(): void
    {
        foreach (array_merge($this->l, $this->m) as $n) {
            DB::table('permissions')->insertOrIgnore(['id' => (string) Str::uuid(), 'permission_name' => $n, 'module_name' => 'Student Elections', 'description' => str_replace('_', ' ', $n), 'created_at' => now()]);
        }$grants = ['Learner' => $this->l, 'Principal' => $this->m, 'Deputy Principal' => $this->m, 'School Admin' => $this->m];
        foreach ($grants as $r => $names) {
            $role = DB::table('roles')->where('role_name', $r)->value('id');
            if (! $role) {
                continue;
            }foreach (DB::table('permissions')->whereIn('permission_name', $names)->pluck('id') as $p) {
                if (! DB::table('role_permissions')->where('role_id', $role)->where('permission_id', $p)->exists()) {
                    DB::table('role_permissions')->insert(['id' => (string) Str::uuid(), 'role_id' => $role, 'permission_id' => $p, 'created_at' => now()]);
                }
            }
        }
    }

    public function down(): void
    {
        $ids = DB::table('permissions')->whereIn('permission_name', array_merge($this->l, $this->m))->pluck('id');
        DB::table('role_permissions')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();
    }
};
