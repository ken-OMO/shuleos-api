<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        $operational = ['manage_fee_discounts', 'assign_learner_discounts', 'apply_fee_discounts', 'manage_payment_plans', 'reschedule_payment_plans', 'request_fee_refunds', 'process_fee_refunds', 'create_finance_adjustments', 'post_finance_adjustments', 'calculate_fee_arrears', 'carry_forward_fee_arrears', 'resolve_fee_arrears', 'view_fee_clearance', 'send_finance_reminders'];
        $approval = ['approve_fee_discounts', 'approve_learner_discounts', 'reverse_fee_discounts', 'approve_payment_plans', 'approve_fee_refunds', 'approve_finance_adjustments', 'reverse_finance_adjustments', 'write_off_fee_balances', 'override_fee_clearance', 'revoke_fee_clearance', 'view_advanced_finance_analytics', 'export_finance_reports'];
        $portal = ['view_own_fee_benefits', 'view_linked_learner_fee_benefits'];

        foreach (array_merge($operational, $approval, $portal) as $name) {
            DB::table('permissions')->insertOrIgnore(['id' => (string) Str::uuid(), 'permission_name' => $name, 'module_name' => 'Finance', 'description' => str_replace('_', ' ', $name), 'created_at' => now()]);
        }

        $grants = ['Finance Officer' => array_merge($operational, $approval), 'Bursar' => array_merge($operational, $approval), 'School Admin' => array_merge($operational, $approval), 'Principal' => $approval, 'Director' => ['view_advanced_finance_analytics', 'export_finance_reports', 'approve_fee_discounts', 'approve_fee_refunds', 'approve_finance_adjustments', 'override_fee_clearance'], 'Parent' => ['view_linked_learner_fee_benefits'], 'Learner' => ['view_own_fee_benefits']];
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
