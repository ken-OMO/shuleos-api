<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    private const TEACHER = ['submit_own_schemes', 'withdraw_own_scheme_submission', 'submit_own_lesson_plans', 'withdraw_own_lesson_plan_submission', 'submit_own_lesson_notes', 'withdraw_own_lesson_note_submission', 'submit_own_records_of_work', 'withdraw_own_record_of_work_submission', 'view_own_workflow_history', 'submit_mark_entry_batches', 'request_mark_corrections', 'use_teacher_offline_sync', 'resolve_own_sync_conflicts', 'upload_teacher_portal_files', 'download_teacher_portal_files', 'manage_own_profile_image', 'manage_own_push_devices', 'view_own_push_delivery_status'];

    private const HOD = ['review_department_teaching_work', 'approve_department_teaching_work', 'request_department_work_changes', 'reject_department_teaching_work', 'view_department_compliance', 'moderate_mark_entry_batches', 'approve_mark_corrections', 'reopen_mark_entry_batches', 'lock_moderated_mark_batches', 'view_hod_analytics'];

    public function up(): void
    {
        $all = array_merge(self::TEACHER, self::HOD);
        foreach ($all as $name) {
            DB::table('permissions')->insertOrIgnore(['id' => (string) Str::uuid(), 'permission_name' => $name, 'module_name' => 'Teacher Portal Phase 2', 'description' => Str::headline($name), 'created_at' => now()]);
        }
        $this->grant(['Teacher'], self::TEACHER);
        $this->grant(['HOD'], $all);
        $this->grant(['Principal', 'Deputy Principal', 'School Admin'], self::HOD);
    }

    private function grant(array $roles, array $permissions): void
    {
        $roleIds = DB::table('roles')->whereIn('role_name', $roles)->pluck('id');
        $permissionIds = DB::table('permissions')->whereIn('permission_name', $permissions)->pluck('id');
        foreach ($roleIds as $roleId) {
            foreach ($permissionIds as $permissionId) {
                DB::table('role_permissions')->insertOrIgnore(['id' => (string) Str::uuid(), 'role_id' => $roleId, 'permission_id' => $permissionId, 'created_at' => now()]);
            }
        }
    }

    public function down(): void {}
};
