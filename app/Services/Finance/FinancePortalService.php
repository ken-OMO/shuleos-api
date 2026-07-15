<?php

namespace App\Services\Finance;

use App\Models\Learner;
use App\Models\User;
use App\Services\LearnerPortal\LearnerPortalAccessService;
use App\Services\ParentPortal\ParentPortalAccessService;
use Illuminate\Support\Facades\DB;

class FinancePortalService
{
    public function __construct(private LearnerPortalAccessService $learnerAccess, private ParentPortalAccessService $parentAccess, private FinanceReceiptService $receipts, private FinanceStatementService $statements, private FinanceClearanceService $clearance) {}

    public function own(User $user): array
    {
        return $this->data($user, $this->learnerAccess->learner($user));
    }

    public function linked(User $user, string $learnerId): array
    {
        return $this->data($user, $this->parentAccess->requireLinkedLearner($user, $learnerId));
    }

    public function ownBenefits(User $user): array
    {
        return $this->benefits($user, $this->learnerAccess->learner($user));
    }

    public function linkedBenefits(User $user, string $learnerId): array
    {
        return $this->benefits($user, $this->parentAccess->requireLinkedLearner($user, $learnerId));
    }

    public function receipt(User $user, Learner $learner, string $paymentId): array
    {
        abort_unless(DB::table('payments')->where('id', $paymentId)->where('school_id', $user->school_id)->where('learner_id', $learner->id)->where('payment_status', 'confirmed')->exists(), 404);

        return $this->receipts->receipt($user, $paymentId);
    }

    private function data(User $user, Learner $learner): array
    {
        $account = DB::table('learner_fee_accounts')->where('school_id', $user->school_id)->where('learner_id', $learner->id)->first();
        if (! $account) {
            return ['available' => false, 'account' => null, 'invoices' => [], 'payments' => [], 'ledger' => []];
        }

        return ['available' => true, 'account' => ['account_number' => $account->account_number, 'current_balance' => $account->current_balance, 'status' => $account->account_status], 'invoices' => DB::table('fee_invoices')->where('school_id', $user->school_id)->where('learner_id', $learner->id)->whereIn('status', ['posted', 'partially_paid', 'paid', 'overpaid'])->select('invoice_number', 'total_amount', 'amount_paid', 'balance', 'status', 'invoice_date', 'due_date')->orderByDesc('invoice_date')->get(), 'payments' => DB::table('payments')->where('school_id', $user->school_id)->where('learner_id', $learner->id)->where('payment_status', 'confirmed')->where('reversed', false)->select('id', 'receipt_number', 'amount', 'allocated_amount', 'payment_date')->orderByDesc('payment_date')->get(), 'ledger' => DB::table('learner_fee_ledger')->where('school_id', $user->school_id)->where('learner_fee_account_id', $account->id)->select('transaction_date', 'transaction_type', 'debit_amount', 'credit_amount', 'running_balance', 'description')->orderByDesc('transaction_date')->limit(100)->get()];
    }

    private function benefits(User $user, Learner $learner): array
    {
        $account = DB::table('learner_fee_accounts')->where('school_id', $user->school_id)->where('learner_id', $learner->id)->first();
        if (! $account) {
            return ['available' => false];
        }
        $plans = DB::table('payment_plans')->where('school_id', $user->school_id)->where('learner_id', $learner->id)->whereIn('status', ['approved', 'active', 'completed', 'defaulted'])->select('id', 'plan_name', 'total_planned_amount', 'status', 'activated_at', 'completed_at')->get();

        return ['available' => true, 'discounts' => DB::table('learner_discounts as assignment')->join('fee_discounts as discount', 'discount.id', '=', 'assignment.discount_id')->where('assignment.school_id', $user->school_id)->where('assignment.learner_id', $learner->id)->where('assignment.status', 'active')->select('discount.discount_name', 'discount.discount_type', 'assignment.assigned_value', 'assignment.starts_at', 'assignment.ends_at')->get(), 'payment_plans' => $plans, 'installments' => DB::table('payment_plan_installments')->where('school_id', $user->school_id)->whereIn('payment_plan_id', $plans->pluck('id'))->select('installment_name', 'scheduled_amount', 'paid_amount', 'outstanding_amount', 'due_date', 'status', 'installment_order')->orderBy('due_date')->get(), 'refunds' => DB::table('fee_refunds')->where('school_id', $user->school_id)->where('learner_id', $learner->id)->select('refund_amount', 'status', 'refund_date', 'created_at')->orderByDesc('created_at')->get(), 'arrears' => DB::table('fee_arrears')->where('school_id', $user->school_id)->where('learner_id', $learner->id)->select('academic_year_id', 'term_id', 'amount', 'carried_forward_amount', 'status', 'calculated_at')->get(), 'statement' => $this->statements->statement($user, $account->id, [], false), 'clearance' => $this->clearance->status($user, $learner->id)];
    }
}
