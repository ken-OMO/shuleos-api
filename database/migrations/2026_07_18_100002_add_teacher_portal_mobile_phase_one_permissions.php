<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    private const PERMISSIONS = [
        'access_teacher_portal', 'view_own_teacher_profile', 'update_own_teacher_profile',
        'view_own_teacher_assignments', 'view_assigned_learners', 'view_own_timetable',
        'manage_authorized_attendance', 'view_own_schemes', 'manage_own_scheme_drafts',
        'view_own_scheme_lessons', 'manage_own_scheme_lessons', 'view_own_lesson_plans',
        'manage_own_lesson_plan_drafts', 'view_own_lesson_notes', 'manage_own_lesson_note_drafts',
        'view_own_records_of_work', 'manage_own_records_of_work', 'view_own_curriculum_coverage',
        'manage_assigned_homework', 'manage_assigned_learning_resources', 'view_assigned_assessments',
        'enter_authorized_marks', 'submit_authorized_marks', 'use_class_teacher_tools',
        'view_teacher_communications', 'send_assigned_class_communications', 'view_teacher_announcements',
        'view_teacher_calendar', 'manage_own_teacher_preferences', 'manage_own_teacher_devices',
        'view_own_teacher_analytics',
    ];

    public function up(): void
    {
        foreach (self::PERMISSIONS as $permission) {
            DB::table('permissions')->insertOrIgnore([
                'id' => (string) Str::uuid(), 'permission_name' => $permission,
                'module_name' => 'Teacher Portal', 'description' => Str::headline($permission), 'created_at' => now(),
            ]);
        }
        $roles = DB::table('roles')->whereRaw('LOWER(role_name) = ?', ['teacher'])->pluck('id');
        $permissions = DB::table('permissions')->whereIn('permission_name', self::PERMISSIONS)->pluck('id');
        foreach ($roles as $role) {
            foreach ($permissions as $permission) {
                DB::table('role_permissions')->insertOrIgnore(['id' => (string) Str::uuid(), 'role_id' => $role, 'permission_id' => $permission, 'created_at' => now()]);
            }
        }
    }

    public function down(): void {}
};
