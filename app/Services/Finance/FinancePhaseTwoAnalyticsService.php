<?php

namespace App\Services\Finance;

use App\Models\User;
use Illuminate\Support\Facades\DB;

class FinancePhaseTwoAnalyticsService
{
    public function __construct(private FinanceMoney $money, private FinanceAnalyticsService $phaseOne) {}

    public function summary(User $user): array
    {
        $base = $this->phaseOne->summary($user);
        $discounts = DB::table('fee_discount_applications')->where('school_id', $user->school_id)->where('status', 'active')->get()->sum(fn ($row) => $this->money->minor($row->discount_amount));
        $refunds = DB::table('fee_refunds')->where('school_id', $user->school_id)->where('status', 'processed')->get()->sum(fn ($row) => $this->money->minor($row->refund_amount));
        $adjustments = DB::table('finance_adjustments')->where('school_id', $user->school_id)->where('status', 'posted')->get();
        $gross = DB::table('fee_invoice_items as item')->join('fee_invoices as invoice', 'invoice.id', '=', 'item.invoice_id')->where('invoice.school_id', $user->school_id)->where('invoice.status', '!=', 'cancelled')->get()->sum(fn ($row) => $this->money->minor($row->amount));
        $collected = $this->money->minor($base['total_collected']);
        $plans = DB::table('payment_plans')->where('school_id', $user->school_id)->where('is_deleted', false)->get();
        $clearances = DB::table('finance_clearances')->where('school_id', $user->school_id)->get();

        return $base + ['gross_invoiced' => $this->money->decimal($gross), 'discounts_and_waivers' => $this->money->decimal($discounts), 'net_invoiced' => $this->money->decimal($gross - $discounts), 'refunds_processed' => $this->money->decimal($refunds), 'net_collections' => $this->money->decimal($collected), 'adjustments_by_type' => $adjustments->groupBy('adjustment_type')->map(fn ($rows) => $this->money->decimal($rows->sum(fn ($row) => $this->money->minor($row->amount)))), 'write_offs' => $this->money->decimal($adjustments->where('adjustment_type', 'write_off')->sum(fn ($row) => $this->money->minor($row->amount))), 'payment_plan_performance' => ['by_status' => $plans->countBy('status'), 'overdue_installments' => DB::table('payment_plan_installments')->where('school_id', $user->school_id)->where('status', 'overdue')->count()], 'discount_distribution' => DB::table('fee_discount_applications as application')->join('fee_discounts as discount', 'discount.id', '=', 'application.discount_id')->where('application.school_id', $user->school_id)->where('application.status', 'active')->select('discount.discount_type', 'application.discount_amount')->get()->groupBy('discount_type')->map(fn ($rows) => $this->money->decimal($rows->sum(fn ($row) => $this->money->minor($row->discount_amount)))), 'refund_statuses' => DB::table('fee_refunds')->where('school_id', $user->school_id)->select('status', DB::raw('COUNT(*) AS aggregate'))->groupBy('status')->pluck('aggregate', 'status'), 'arrears_by_status' => DB::table('fee_arrears')->where('school_id', $user->school_id)->select('status', DB::raw('SUM(amount) AS aggregate'))->groupBy('status')->pluck('aggregate', 'status'), 'clearance_rates' => ['total' => $clearances->count(), 'cleared' => $clearances->where('status', 'cleared')->count(), 'conditional' => $clearances->where('status', 'conditionally_cleared')->count()]];
    }
}
