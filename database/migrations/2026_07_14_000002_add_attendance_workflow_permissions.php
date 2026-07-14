<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    private array $names = ['create_attendance_register', 'mark_attendance', 'finalize_attendance', 'correct_attendance', 'view_attendance_analytics', 'view_attendance_alerts', 'manage_attendance_statuses', 'manage_attendance_sessions'];

    public function up(): void
    {
        foreach ($this->names as $n) {
            DB::table('permissions')->insertOrIgnore(['id' => (string) Str::uuid(), 'permission_name' => $n, 'module_name' => 'Attendance', 'description' => str_replace('_', ' ', $n), 'created_at' => now()]);
        }foreach (['Teacher' => array_slice($this->names, 0, 3), 'HOD' => ['view_attendance_analytics', 'view_attendance_alerts'], 'Principal' => $this->names, 'Deputy Principal' => $this->names, 'School Admin' => $this->names] as $role => $names) {
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
        $ids = DB::table('permissions')->whereIn('permission_name', $this->names)->pluck('id');
        DB::table('role_permissions')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();
    }
};
