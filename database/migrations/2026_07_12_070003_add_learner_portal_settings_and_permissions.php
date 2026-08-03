<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    private array $p = ['access_learner_portal', 'view_learner_dashboard', 'view_own_timetable', 'view_own_attendance', 'view_own_results', 'view_own_report_cards', 'download_own_report_cards', 'view_own_fees', 'view_learner_announcements', 'manage_learner_portal_accounts', 'manage_learner_dashboard_preferences'];

    public function up(): void
    {
        Schema::table('school_settings', function (Blueprint $t) {
            $t->boolean('learner_portal_enabled')->default(true);
            $t->boolean('learner_portal_show_fees')->default(false);
            $t->boolean('learner_portal_show_positions')->default(false);
            $t->boolean('learner_portal_show_pathway')->default(true);
            $t->boolean('learner_portal_show_report_cards')->default(true);
            $t->boolean('learner_portal_show_attendance')->default(true);
            $t->boolean('learner_portal_show_results')->default(true);
        });
        foreach ($this->p as $n) {
            DB::table('permissions')->insertOrIgnore(['id' => (string) Str::uuid(), 'permission_name' => $n, 'module_name' => 'Learner Portal', 'description' => str_replace('_', ' ', $n), 'created_at' => now()]);
        }$grants = ['Learner' => array_diff($this->p, ['manage_learner_portal_accounts']), 'School Admin' => ['manage_learner_portal_accounts'], 'Administrator' => ['manage_learner_portal_accounts'], 'Principal' => ['manage_learner_portal_accounts']];
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
        Schema::table('school_settings', fn (Blueprint $t) => $t->dropColumn(['learner_portal_enabled', 'learner_portal_show_fees', 'learner_portal_show_positions', 'learner_portal_show_pathway', 'learner_portal_show_report_cards', 'learner_portal_show_attendance', 'learner_portal_show_results']));
        $ids = DB::table('permissions')->whereIn('permission_name', $this->p)->pluck('id');
        DB::table('role_permissions')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();
    }
};
