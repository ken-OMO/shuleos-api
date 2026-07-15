<?php

namespace App\Services\Finance;

use App\Models\Learner;
use App\Models\User;
use App\Services\LearnerPortal\LearnerPortalAccessService;
use App\Services\ParentPortal\ParentPortalAccessService;
use Illuminate\Support\Facades\DB;

class FinancePortalService
{
    public function __construct(private LearnerPortalAccessService $learnerAccess, private ParentPortalAccessService $parentAccess, private FinanceReceiptService $receipts) {}

    public function own(User $user): array
    {
        return $this->data($user, $this->learnerAccess->learner($user));
    }

    public function linked(User $user, string $learnerId): array
    {
        return $this->data($user, $this->parentAccess->requireLinkedLearner($user, $learnerId));
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
}
