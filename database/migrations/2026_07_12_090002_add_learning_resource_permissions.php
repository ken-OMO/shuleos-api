<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    private array $t = ['access_learning_resources', 'create_learning_resources', 'upload_learning_resource_files', 'manage_own_learning_resources', 'submit_learning_resources_for_review'];

    private array $l = ['view_learning_resources', 'download_learning_resources', 'bookmark_learning_resources', 'rate_learning_resources'];

    private array $p = ['view_linked_learner_resources', 'download_linked_learner_resources'];

    private array $a = ['manage_learning_resource_categories', 'review_learning_resources', 'approve_learning_resources', 'publish_learning_resources', 'archive_learning_resources', 'view_learning_resource_analytics', 'manage_all_learning_resources'];

    public function up(): void
    {
        foreach (array_merge($this->t, $this->l, $this->p, $this->a) as $n) {
            DB::table('permissions')->insertOrIgnore(['id' => (string) Str::uuid(), 'permission_name' => $n, 'module_name' => 'Learning Resources', 'description' => str_replace('_', ' ', $n), 'created_at' => now()]);
        }$grants = ['Teacher' => $this->t, 'Learner' => $this->l, 'Parent' => $this->p, 'HOD' => ['review_learning_resources', 'approve_learning_resources', 'view_learning_resource_analytics'], 'Principal' => $this->a, 'Deputy Principal' => $this->a, 'School Admin' => $this->a];
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
        $ids = DB::table('permissions')->whereIn('permission_name', array_merge($this->t, $this->l, $this->p, $this->a))->pluck('id');
        DB::table('role_permissions')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();
    }
};
