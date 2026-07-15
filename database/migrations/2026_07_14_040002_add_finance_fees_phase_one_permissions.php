<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        $ops = ['manage_fee_categories', 'manage_fee_structures', 'approve_fee_structures', 'generate_fee_invoices', 'post_fee_invoices', 'cancel_fee_invoices', 'provision_fee_accounts', 'record_fee_payments', 'confirm_fee_payments', 'reverse_fee_payments', 'allocate_fee_payments', 'view_finance_receipts', 'manage_finance_settings', 'view_finance_analytics', 'reconcile_fee_ledger'];
        foreach (array_merge($ops, ['view_own_fees', 'view_linked_learner_fees']) as $name) {
            DB::table('permissions')->insertOrIgnore(['id' => (string) Str::uuid(), 'permission_name' => $name, 'module_name' => 'Finance', 'description' => str_replace('_', ' ', $name), 'created_at' => now()]);
        }
        $grants = ['Finance Officer' => $ops, 'Bursar' => $ops, 'School Admin' => $ops, 'Principal' => ['approve_fee_structures', 'view_finance_analytics', 'reconcile_fee_ledger'], 'Director' => ['approve_fee_structures', 'view_finance_analytics'], 'Parent' => ['view_linked_learner_fees'], 'Learner' => ['view_own_fees']];
        foreach ($grants as $role => $permissions) {
            $roleId = DB::table('roles')->where('role_name', $role)->value('id');
            if (! $roleId) {
                continue;
            }
            foreach (DB::table('permissions')->whereIn('permission_name', $permissions)->pluck('id') as $permissionId) {
                DB::table('role_permissions')->insertOrIgnore(['id' => (string) Str::uuid(), 'role_id' => $roleId, 'permission_id' => $permissionId, 'created_at' => now()]);
            }
        }
    }

    public function down(): void {}
};
