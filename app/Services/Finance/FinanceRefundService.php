<?php

namespace App\Services\Finance;

use App\Models\FeeInvoice;
use App\Models\LearnerFeeAccount;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class FinanceRefundService
{
    public function __construct(private FinanceMoney $money, private FinanceLedgerService $ledger, private FinanceAuditService $audit, private FinanceInstallmentService $installments) {}

    public function request(User $user, array $data): object
    {
        return DB::transaction(function () use ($user, $data) {
            $payment = Payment::whereKey($data['payment_id'])->where('school_id', $user->school_id)->where('payment_status', 'confirmed')->where('reversed', false)->lockForUpdate()->firstOrFail();
            $amount = $this->money->positive($data['amount']);
            $refundable = $this->money->minor($payment->allocated_amount) - $this->money->minor($payment->refunded_amount ?? '0.00');
            $pending = DB::table('fee_refunds')->where('school_id', $user->school_id)->where('payment_id', $payment->id)->whereIn('status', ['requested', 'under_review', 'approved'])->get()->sum(fn ($refund) => $this->money->minor($refund->refund_amount));
            if ($this->money->minor($amount) > $refundable - $pending) {
                throw ValidationException::withMessages(['amount' => 'Refund exceeds the remaining refundable allocated payment.']);
            }
            $account = LearnerFeeAccount::where('school_id', $user->school_id)->where('learner_id', $payment->learner_id)->firstOrFail();
            $id = (string) Str::uuid();
            DB::table('fee_refunds')->insert(['id' => $id, 'school_id' => $user->school_id, 'learner_id' => $payment->learner_id, 'learner_fee_account_id' => $account->id, 'payment_id' => $payment->id, 'refund_amount' => $amount, 'reason' => $data['reason'], 'status' => 'requested', 'requested_by' => $user->id, 'requested_at' => now(), 'refund_date' => now()->toDateString(), 'created_at' => now()]);
            $this->audit->record($user, 'refund_requested', 'fee_refunds', $id, [], ['amount' => $amount]);

            return $this->find($user, $id);
        });
    }

    public function decide(User $user, string $id, string $decision, ?string $reason = null): object
    {
        return DB::transaction(function () use ($user, $id, $decision, $reason) {
            $refund = DB::table('fee_refunds')->where('id', $id)->where('school_id', $user->school_id)->whereIn('status', ['requested', 'under_review'])->lockForUpdate()->first();
            abort_unless($refund, 404);
            if ($decision === 'approved' && $refund->requested_by === $user->id) {
                throw ValidationException::withMessages(['approval' => 'Requester cannot approve their own refund.']);
            }
            if (! in_array($decision, ['approved', 'rejected'], true)) {
                throw ValidationException::withMessages(['status' => 'Invalid refund decision.']);
            }
            $values = ['status' => $decision, 'updated_at' => now()];
            if ($decision === 'approved') {
                $values += ['approved_by' => $user->id, 'approved_at' => now()];
            } else {
                $values += ['rejected_by' => $user->id, 'rejected_at' => now(), 'decision_reason' => $reason];
            }
            DB::table('fee_refunds')->where('id', $id)->update($values);
            $this->audit->record($user, 'refund_'.$decision, 'fee_refunds', $id);
            app(FinanceNotificationService::class)->forLearner($user->school_id, $refund->learner_id, 'finance_refund_'.$decision, 'finance:refund:'.$id.':'.$decision, 'Fee refund '.$decision, 'Your fee refund request status has changed.');

            return $this->find($user, $id);
        });
    }

    public function process(User $user, string $id): object
    {
        return DB::transaction(function () use ($user, $id) {
            $refund = DB::table('fee_refunds')->where('id', $id)->where('school_id', $user->school_id)->where('status', 'approved')->lockForUpdate()->first();
            abort_unless($refund, 404);
            $payment = Payment::whereKey($refund->payment_id)->where('school_id', $user->school_id)->where('reversed', false)->lockForUpdate()->firstOrFail();
            $remaining = $this->money->minor($refund->refund_amount);
            $account = LearnerFeeAccount::whereKey($refund->learner_fee_account_id)->where('school_id', $user->school_id)->firstOrFail();
            $ledgerId = $this->ledger->post($user, $account, ['transaction_type' => 'fee_refund', 'reference_type' => 'fee_refund', 'reference_id' => $refund->id, 'debit' => $refund->refund_amount, 'description' => 'Processed refund for '.$payment->receipt_number]);
            foreach (DB::table('payment_allocations')->where('school_id', $user->school_id)->where('payment_id', $payment->id)->where('status', 'active')->orderByDesc('created_at')->lockForUpdate()->get() as $allocation) {
                if ($remaining <= 0) {
                    break;
                }
                $available = $this->money->minor($allocation->allocated_amount) - $this->money->minor($allocation->refunded_amount ?? '0.00');
                $take = min($remaining, $available);
                DB::table('payment_allocations')->where('id', $allocation->id)->update(['refunded_amount' => $this->money->decimal($this->money->minor($allocation->refunded_amount ?? '0.00') + $take)]);
                $invoice = FeeInvoice::whereKey($allocation->invoice_id)->where('school_id', $user->school_id)->lockForUpdate()->firstOrFail();
                $paid = DB::table('payment_allocations')->where('invoice_id', $invoice->id)->where('status', 'active')->get()->sum(fn ($row) => $this->money->minor($row->allocated_amount) - $this->money->minor($row->refunded_amount ?? '0.00'));
                $balance = max(0, $this->money->minor($invoice->total_amount) - $paid);
                $invoice->update(['amount_paid' => $this->money->decimal($paid), 'balance' => $this->money->decimal($balance), 'status' => $paid > 0 ? 'partially_paid' : 'posted', 'updated_at' => now()]);
                $remaining -= $take;
            }
            abort_if($remaining > 0, 409, 'Refund no longer has enough valid allocated credit.');
            $payment->update(['refunded_amount' => $this->money->decimal($this->money->minor($payment->refunded_amount ?? '0.00') + $this->money->minor($refund->refund_amount)), 'updated_at' => now()]);
            DB::table('fee_refunds')->where('id', $id)->update(['status' => 'processed', 'processed_by' => $user->id, 'processed_at' => now(), 'ledger_entry_id' => $ledgerId, 'refund_date' => now()->toDateString(), 'updated_at' => now()]);
            $this->installments->refreshForLearner($user, $payment->learner_id);
            $this->audit->record($user, 'refund_processed', 'fee_refunds', $id);
            app(FinanceNotificationService::class)->forLearner($user->school_id, $refund->learner_id, 'finance_refund_processed', 'finance:refund:'.$id.':processed', 'Fee refund processed', 'Your approved fee refund has been marked as processed.');

            return $this->find($user, $id);
        });
    }

    public function cancel(User $user, string $id, string $reason): object
    {
        $updated = DB::table('fee_refunds')->where('id', $id)->where('school_id', $user->school_id)->whereIn('status', ['requested', 'under_review', 'approved'])->update(['status' => 'cancelled', 'cancelled_by' => $user->id, 'cancelled_at' => now(), 'decision_reason' => $reason, 'updated_at' => now()]);
        abort_unless($updated, 404);
        $this->audit->record($user, 'refund_cancelled', 'fee_refunds', $id);

        return $this->find($user, $id);
    }

    public function find(User $user, string $id): object
    {
        $row = DB::table('fee_refunds')->where('id', $id)->where('school_id', $user->school_id)->first();
        abort_unless($row, 404);

        return $row;
    }
}
