<?php

namespace App\Services\Finance;

use App\Models\User;
use Illuminate\Support\Facades\DB;

class FinanceInstallmentService
{
    public function __construct(private FinanceMoney $money) {}

    public function refreshPlan(User $user, string $planId): void
    {
        DB::transaction(function () use ($user, $planId) {
            $plan = DB::table('payment_plans')->where('id', $planId)->where('school_id', $user->school_id)->lockForUpdate()->first();
            abort_unless($plan, 404);
            $invoiceIds = DB::table('payment_plan_invoices')->where('school_id', $user->school_id)->where('payment_plan_id', $plan->id)->pluck('invoice_id');
            $paidMinor = DB::table('payment_allocations')->where('school_id', $user->school_id)->whereIn('invoice_id', $invoiceIds)->where('status', 'active')->get()->sum(fn ($allocation) => $this->money->minor($allocation->allocated_amount) - $this->money->minor($allocation->refunded_amount ?? '0.00'));
            foreach (DB::table('payment_plan_installments')->where('payment_plan_id', $plan->id)->orderBy('due_date')->orderBy('installment_order')->lockForUpdate()->get() as $installment) {
                $scheduled = $this->money->minor($installment->scheduled_amount);
                $applied = min($paidMinor, $scheduled);
                $paidMinor -= $applied;
                $outstanding = $scheduled - $applied;
                $status = $outstanding === 0 ? 'paid' : ($applied > 0 ? 'partially_paid' : (($installment->due_date && $installment->due_date < now()->toDateString()) ? 'overdue' : 'pending'));
                DB::table('payment_plan_installments')->where('id', $installment->id)->update(['paid_amount' => $this->money->decimal($applied), 'outstanding_amount' => $this->money->decimal($outstanding), 'status' => $status, 'updated_at' => now()]);
            }
            $remaining = DB::table('payment_plan_installments')->where('payment_plan_id', $plan->id)->whereNotIn('status', ['paid', 'waived', 'cancelled'])->exists();
            if (! $remaining && $plan->status === 'active') {
                DB::table('payment_plans')->where('id', $plan->id)->update(['status' => 'completed', 'completed_at' => now(), 'active' => false, 'updated_at' => now()]);
            }
        });
    }

    public function refreshForLearner(User $user, string $learnerId): void
    {
        DB::table('payment_plans')->where('school_id', $user->school_id)->where('learner_id', $learnerId)->whereIn('status', ['active', 'completed'])->pluck('id')->each(fn ($id) => $this->refreshPlan($user, $id));
    }
}
