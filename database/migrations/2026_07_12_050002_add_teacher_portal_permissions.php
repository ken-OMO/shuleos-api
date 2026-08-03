<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    private array $p = ['access_teacher_portal', 'view_teacher_dashboard', 'view_teacher_classes', 'view_teacher_learners', 'view_teacher_analytics'];

    public function up(): void
    {
        foreach ($this->p as $n) {
            DB::table('permissions')->insertOrIgnore(['id' => (string) Str::uuid(), 'permission_name' => $n, 'module_name' => 'Teacher Portal', 'description' => str_replace('_', ' ', $n), 'created_at' => now()]);
        }$role = DB::table('roles')->whereRaw('LOWER(role_name)=?', ['teacher'])->value('id');
        if ($role) {
            foreach (DB::table('permissions')->whereIn('permission_name', $this->p)->pluck('id') as $x) {
                if (! DB::table('role_permissions')->where('role_id', $role)->where('permission_id', $x)->exists()) {
                    DB::table('role_permissions')->insert(['id' => (string) Str::uuid(), 'role_id' => $role, 'permission_id' => $x, 'created_at' => now()]);
                }
            }
        }
    }

    public function down(): void
    {
        $ids = DB::table('permissions')->whereIn('permission_name', $this->p)->pluck('id');
        DB::table('role_permissions')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();
    }
};
