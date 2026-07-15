<?php

namespace App\Services\Finance;

use App\Models\FeeInvoice;
use App\Models\LearnerFeeAccount;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class FinancePaymentService
{
    public function __construct(private FinanceMoney $money, private FinanceLedgerService $ledger, private FinanceAuditService $audit) {}

    public function create(User $user, array $data): Payment
    {
        return DB::transaction(function () use ($user, $data) {
            $account = LearnerFeeAccount::whereKey($data['learner_fee_account_id'])->where('school_id', $user->school_id)->where('account_status', 'active')->firstOrFail();
            $method = DB::table('payment_methods')->where('id', $data['payment_method_id'])->where('school_id', $user->school_id)->where('active', true)->where('is_online', false)->first();
            if (! $method) {
                throw ValidationException::withMessages(['payment_method_id' => 'An active manual payment method is required.']);
            }
            $channel = strtolower(str_replace([' ', '-'], '_', $method->method_name));
            if (! in_array($channel, ['cash', 'cheque', 'bank_deposit', 'bank_transfer', 'other_manual'], true)) {
                throw ValidationException::withMessages(['payment_method_id' => 'This payment channel is not supported in Finance Phase 1.']);
            }
            $amount = $this->money->positive($data['amount']);
            $settings = DB::table('finance_settings')->where('school_id', $user->school_id)->where('active', true)->first();
            if (! ($settings?->allow_overpayments ?? false)) {
                $outstanding = FeeInvoice::where('school_id', $user->school_id)->where('learner_id', $account->learner_id)->whereIn('status', ['posted', 'partially_paid'])->get()->sum(fn ($invoice) => $this->money->minor($invoice->balance));
                if ($this->money->minor($amount) > $outstanding) {
                    throw ValidationException::withMessages(['amount' => 'Payment exceeds the learner outstanding balance and overpayments are disabled.']);
                }
            }
            if (! empty($data['transaction_reference']) && Payment::where('school_id', $user->school_id)->where('transaction_reference', $data['transaction_reference'])->exists()) {
                throw ValidationException::withMessages(['transaction_reference' => 'Transaction reference already exists.']);
            }
            $payment = Payment::create(['id' => (string) Str::uuid(), 'school_id' => $user->school_id, 'learner_id' => $account->learner_id, 'payment_method_id' => $method->id, 'receipt_number' => 'RCT-'.now()->format('Ymd').'-'.strtoupper(substr(str_replace('-', '', (string) Str::uuid()), 0, 10)), 'amount' => $amount, 'allocated_amount' => '0.00', 'payment_channel' => $channel, 'transaction_reference' => $data['transaction_reference'] ?? null, 'payment_date' => $data['payment_date'], 'received_by' => $user->id, 'payment_status' => 'pending', 'reversed' => false, 'payer_phone' => $data['payer_phone'] ?? null, 'payer_name' => $data['payer_name'] ?? null, 'remarks' => $data['remarks'] ?? null]);
            $this->audit->record($user, 'payment_recorded', 'payments', $payment->id, [], ['amount' => $amount]);

            return $payment;
        });
    }

    public function confirm(User $user, string $id): Payment
    {
        return DB::transaction(function () use ($user, $id) {
            $payment = Payment::whereKey($id)->where('school_id', $user->school_id)->lockForUpdate()->firstOrFail();
            if ($payment->payment_status === 'confirmed') {
                return $payment;
            }
            abort_if($payment->reversed, 409, 'Reversed payment cannot be confirmed.');
            $payment->update(['payment_status' => 'confirmed', 'confirmed_by' => $user->id, 'confirmed_at' => now(), 'posted_by' => $user->id, 'updated_at' => now()]);
            $this->audit->record($user, 'payment_confirmed', 'payments', $payment->id);
            app(FinanceNotificationService::class)->forLearner($user->school_id, $payment->learner_id, 'finance_payment_confirmed', 'finance:payment:'.$payment->id.':confirmed', 'Fee payment confirmed', 'Your fee payment has been confirmed.');

            return $payment;
        });
    }

    public function allocate(User $user, string $paymentId, string $invoiceId, string|int $requested): PaymentAllocation
    {
        return DB::transaction(function () use ($user, $paymentId, $invoiceId, $requested) {
            $payment = Payment::whereKey($paymentId)->where('school_id', $user->school_id)->where('payment_status', 'confirmed')->where('reversed', false)->lockForUpdate()->firstOrFail();
            $invoice = FeeInvoice::whereKey($invoiceId)->where('school_id', $user->school_id)->where('learner_id', $payment->learner_id)->whereIn('status', ['posted', 'partially_paid'])->lockForUpdate()->firstOrFail();
            $available = $this->money->minor($payment->amount) - $this->money->minor($payment->allocated_amount);
            $balance = $this->money->minor($invoice->total_amount) - $invoice->allocations()->where('status', 'active')->get()->sum(fn ($allocation) => $this->money->minor($allocation->allocated_amount) - $this->money->minor($allocation->refunded_amount ?? '0.00'));
            $amount = $this->money->minor($this->money->positive($requested));
            if ($amount > $available || $amount > $balance) {
                throw ValidationException::withMessages(['amount' => 'Allocation exceeds available payment or invoice balance.']);
            }
            $allowPartial = (bool) (DB::table('finance_settings')->where('school_id', $user->school_id)->where('active', true)->value('allow_partial_payments') ?? true);
            if (! $allowPartial && $amount < $balance) {
                throw ValidationException::withMessages(['amount' => 'Partial payments are disabled for this school.']);
            }
            $account = LearnerFeeAccount::where('school_id', $user->school_id)->where('learner_id', $payment->learner_id)->firstOrFail();
            $allocation = PaymentAllocation::create(['id' => (string) Str::uuid(), 'school_id' => $user->school_id, 'payment_id' => $payment->id, 'invoice_id' => $invoice->id, 'allocated_amount' => $this->money->decimal($amount), 'created_by' => $user->id, 'status' => 'active']);
            $ledgerId = $this->ledger->post($user, $account, ['academic_year_id' => $invoice->academic_year_id, 'term_id' => $invoice->term_id, 'transaction_type' => 'payment_allocation', 'reference_type' => 'payment_allocation', 'reference_id' => $allocation->id, 'credit' => $this->money->decimal($amount), 'description' => 'Payment allocated to '.$invoice->invoice_number]);
            $allocation->update(['ledger_entry_id' => $ledgerId]);
            $newAllocated = $this->money->minor($payment->allocated_amount) + $amount;
            $newPaid = $invoice->allocations()->where('status', 'active')->get()->sum(fn ($row) => $this->money->minor($row->allocated_amount) - $this->money->minor($row->refunded_amount ?? '0.00'));
            $newBalance = $this->money->minor($invoice->total_amount) - $newPaid;
            $payment->update(['allocated_amount' => $this->money->decimal($newAllocated), 'updated_at' => now()]);
            $invoice->update(['amount_paid' => $this->money->decimal($newPaid), 'balance' => $this->money->decimal($newBalance), 'status' => $newBalance === 0 ? 'paid' : 'partially_paid', 'updated_at' => now()]);
            $account->update(['last_payment_date' => $payment->payment_date]);
            $this->audit->record($user, 'payment_allocated', 'payment_allocations', $allocation->id, [], ['amount' => $allocation->allocated_amount]);
            app(FinanceInstallmentService::class)->refreshForLearner($user, $payment->learner_id);

            return $allocation;
        });
    }

    public function autoAllocate(User $user, string $paymentId): array
    {
        $payment = Payment::whereKey($paymentId)->where('school_id', $user->school_id)->where('payment_status', 'confirmed')->where('reversed', false)->firstOrFail();
        $allocations = [];
        foreach (FeeInvoice::where('school_id', $user->school_id)->where('learner_id', $payment->learner_id)->whereIn('status', ['posted', 'partially_paid'])->orderBy('due_date')->orderBy('invoice_date')->get() as $invoice) {
            $available = $this->money->minor($payment->fresh()->amount) - $this->money->minor($payment->fresh()->allocated_amount);
            if ($available <= 0) {
                break;
            }
            $balance = $this->money->minor($invoice->balance);
            if ($balance > 0) {
                $allocations[] = $this->allocate($user, $payment->id, $invoice->id, $this->money->decimal(min($available, $balance)));
            }
        }

        return $allocations;
    }

    public function reverse(User $user, string $id, string $reason): Payment
    {
        return DB::transaction(function () use ($user, $id, $reason) {
            $payment = Payment::whereKey($id)->where('school_id', $user->school_id)->where('payment_status', 'confirmed')->where('reversed', false)->lockForUpdate()->firstOrFail();
            $account = LearnerFeeAccount::where('school_id', $user->school_id)->where('learner_id', $payment->learner_id)->firstOrFail();
            foreach ($payment->allocations()->where('status', 'active')->lockForUpdate()->get() as $allocation) {
                $invoice = FeeInvoice::whereKey($allocation->invoice_id)->lockForUpdate()->firstOrFail();
                $netAllocation = $this->money->minor($allocation->allocated_amount) - $this->money->minor($allocation->refunded_amount ?? '0.00');
                if ($netAllocation > 0) {
                    $this->ledger->post($user, $account, ['academic_year_id' => $invoice->academic_year_id, 'term_id' => $invoice->term_id, 'transaction_type' => 'payment_reversal', 'reference_type' => 'payment_allocation', 'reference_id' => $allocation->id, 'posting_action' => 'reversal', 'debit' => $this->money->decimal($netAllocation), 'reverses_ledger_id' => $allocation->ledger_entry_id, 'description' => 'Reversal of payment '.$payment->receipt_number]);
                }
                $allocation->update(['status' => 'reversed', 'reversed_by' => $user->id, 'reversed_at' => now(), 'reversal_reason' => $reason]);
                $paid = $invoice->allocations()->where('status', 'active')->get()->sum(fn ($row) => $this->money->minor($row->allocated_amount) - $this->money->minor($row->refunded_amount ?? '0.00'));
                $balance = $this->money->minor($invoice->total_amount) - $paid;
                $invoice->update(['amount_paid' => $this->money->decimal($paid), 'balance' => $this->money->decimal($balance), 'status' => $paid > 0 ? 'partially_paid' : 'posted']);
            }
            $payment->update(['reversed' => true, 'payment_status' => 'reversed', 'reversal_reason' => $reason, 'reversed_by' => $user->id, 'reversed_at' => now(), 'updated_at' => now()]);
            app(FinanceInstallmentService::class)->refreshForLearner($user, $payment->learner_id);
            $this->audit->record($user, 'payment_reversed', 'payments', $payment->id);
            app(FinanceNotificationService::class)->forLearner($user->school_id, $payment->learner_id, 'finance_payment_reversed', 'finance:payment:'.$payment->id.':reversed', 'Fee payment reversed', 'A fee payment was reversed. View your fee account for details.');

            return $payment;
        });
    }
}
