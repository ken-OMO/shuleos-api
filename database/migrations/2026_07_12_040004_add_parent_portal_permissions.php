<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    private array $parent = ['access_parent_portal', 'view_linked_learners', 'view_parent_report_cards', 'download_parent_report_cards', 'view_parent_attendance', 'view_parent_fees', 'view_parent_announcements'];

    private array $admin = ['manage_report_card_access_policy', 'manage_report_card_access_overrides'];

    public function up(): void
    {
        foreach (array_merge($this->parent, $this->admin) as $name) {
            DB::table('permissions')->insertOrIgnore(['id' => (string) Str::uuid(), 'permission_name' => $name, 'module_name' => 'Parent Portal', 'description' => str_replace('_', ' ', ucfirst($name)), 'created_at' => now()]);
        }$parent = DB::table('roles')->whereRaw('LOWER(role_name)=?', ['parent'])->value('id');
        if ($parent) {
            $this->grant($parent, $this->parent);
        }foreach (DB::table('roles')->whereIn('role_name', ['School Admin', 'Administrator', 'Principal'])->pluck('id') as $role) {
            $this->grant($role, $this->admin);
        }
    }

    private function grant(string $role, array $names): void
    {
        foreach (DB::table('permissions')->whereIn('permission_name', $names)->pluck('id') as $p) {
            if (! DB::table('role_permissions')->where('role_id', $role)->where('permission_id', $p)->exists()) {
                DB::table('role_permissions')->insert(['id' => (string) Str::uuid(), 'role_id' => $role, 'permission_id' => $p, 'created_at' => now()]);
            }
        }
    }

    public function down(): void
    {
        $ids = DB::table('permissions')->whereIn('permission_name', array_merge($this->parent, $this->admin))->pluck('id');
        DB::table('role_permissions')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();
    }
};
