<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    private array $teacher = ['access_homework_assignments', 'create_homework_assignments', 'manage_own_homework_assignments', 'publish_homework_assignments', 'view_homework_submissions', 'mark_homework_submissions', 'release_homework_feedback', 'download_homework_submissions'];

    private array $learner = ['view_own_homework', 'submit_homework', 'upload_homework_submission_files', 'view_own_homework_feedback', 'download_own_homework_submission_files'];

    private array $parent = ['view_linked_learner_homework', 'view_linked_learner_homework_feedback'];

    private array $leadership = ['view_homework_analytics', 'view_all_homework_assignments', 'manage_all_homework_assignments', 'moderate_homework_marks'];

    public function up(): void
    {
        $all = array_merge($this->teacher, $this->learner, $this->parent, $this->leadership);
        foreach ($all as $name) {
            DB::table('permissions')->insertOrIgnore(['id' => (string) Str::uuid(), 'permission_name' => $name, 'module_name' => 'Homework', 'description' => str_replace('_', ' ', $name), 'created_at' => now()]);
        }
        foreach (['Teacher' => $this->teacher, 'Learner' => $this->learner, 'Parent' => $this->parent, 'HOD' => ['view_homework_analytics', 'view_all_homework_assignments'], 'Principal' => $this->leadership, 'Deputy Principal' => $this->leadership, 'School Admin' => $this->leadership] as $roleName => $permissions) {
            $role = DB::table('roles')->where('role_name', $roleName)->value('id');
            if (! $role) {
                continue;
            }
            foreach (DB::table('permissions')->whereIn('permission_name', $permissions)->pluck('id') as $permission) {
                DB::table('role_permissions')->insertOrIgnore(['id' => (string) Str::uuid(), 'role_id' => $role, 'permission_id' => $permission, 'created_at' => now()]);
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
