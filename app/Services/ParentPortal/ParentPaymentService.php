<?php

namespace App\Services\ParentPortal;

use App\Contracts\ParentPortal\PaymentProviderInterface;
use App\Jobs\InitiateParentPayment;
use App\Models\FeeInvoice;
use App\Models\LearnerFeeAccount;
use App\Models\ParentPaymentAttempt;
use App\Models\ParentPaymentAttemptHistory;
use App\Models\User;
use App\Services\Finance\FinanceMoney;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ParentPaymentService
{
    public function __construct(private ParentPortalAccessService $access, private PaymentProviderInterface $provider, private FinanceMoney $money) {}

    public function health(User $user): array
    {
        $this->access->parent($user);

        return ['provider' => config('parent_portal_phase_two.payment_provider'), 'ready' => $this->provider->ready()];
    }

    public function preview(User $user, string $learnerId, array $data): array
    {
        $learner = $this->access->requireLinkedLearner($user, $learnerId);
        [$account, $invoice, $balance] = $this->account($user, $learner->id, $data['invoice_id'] ?? null);
        $amount = $this->money->minor($data['amount']);
        $minimum = min((int) config('parent_portal_phase_two.minimum_payment_minor'), $balance);
        $maximum = min((int) config('parent_portal_phase_two.maximum_payment_minor'), $balance);
        if ($amount < $minimum || $amount > $maximum) {
            throw ValidationException::withMessages(['amount' => 'Amount must be within the current payable range.']);
        }
        $phone = $this->phone($this->access->parent($user)->phone, $data['phone'] ?? null);

        return [
            'learner' => ['id' => $learner->id, 'name' => trim($learner->first_name.' '.$learner->last_name)],
            'invoice' => $invoice ? ['id' => $invoice->id, 'number' => $invoice->invoice_number, 'balance' => $invoice->balance] : null,
            'account_id' => $account->id, 'current_balance' => $this->money->decimal($balance),
            'minimum_payable' => $this->money->decimal($minimum), 'maximum_payable' => $this->money->decimal($maximum),
            'amount_requested' => $this->money->decimal($amount), 'allocation_preview' => $invoice ? [['invoice_number' => $invoice->invoice_number, 'amount' => $this->money->decimal($amount)]] : [],
            'phone_masked' => $this->mask($phone), 'provider_ready' => $this->provider->ready(),
            'warnings' => $this->provider->ready() ? [] : ['Payment provider is not ready.'], 'idempotency_required' => true,
        ];
    }

    public function initiate(User $user, string $learnerId, array $data): ParentPaymentAttempt
    {
        $preview = $this->preview($user, $learnerId, $data);
        $phone = $this->phone($this->access->parent($user)->phone, $data['phone'] ?? null);
        $hash = hash('sha256', $data['idempotency_key']);

        $attempt = DB::transaction(function () use ($user, $learnerId, $data, $preview, $phone, $hash) {
            $existing = ParentPaymentAttempt::withoutGlobalScopes()->where('school_id', $user->school_id)->where('parent_user_id', $user->id)->where('idempotency_key_hash', $hash)->lockForUpdate()->first();
            if ($existing) {
                return $existing;
            }
            $attempt = ParentPaymentAttempt::withoutGlobalScopes()->create([
                'id' => (string) Str::uuid(), 'school_id' => $user->school_id, 'parent_user_id' => $user->id, 'learner_id' => $learnerId,
                'invoice_id' => $data['invoice_id'] ?? null, 'payment_reference' => 'PPS-'.now()->format('YmdHis').'-'.strtoupper(Str::random(8)),
                'idempotency_key_hash' => $hash, 'provider' => config('parent_portal_phase_two.payment_provider'),
                'phone_hash' => hash('sha256', $phone), 'phone_masked' => $this->mask($phone),
                'amount_minor' => $this->money->minor($preview['amount_requested']), 'currency' => 'KES', 'status' => 'pending', 'initiated_at' => now(),
            ]);
            $this->history($attempt, null, 'pending', $user->id);

            return $attempt;
        });

        if ($attempt->status === 'pending') {
            InitiateParentPayment::dispatch($attempt->id, $phone)->afterCommit();
        }

        return $attempt;
    }

    public function owned(User $user, string $id): ParentPaymentAttempt
    {
        $this->access->parent($user);

        return ParentPaymentAttempt::withoutGlobalScopes()->whereKey($id)->where('school_id', $user->school_id)->where('parent_user_id', $user->id)->with('learner:id,first_name,last_name', 'payment:id,receipt_number,allocated_amount')->firstOrFail();
    }

    public function index(User $user, ?string $learnerId = null)
    {
        $this->access->parent($user);
        if ($learnerId) {
            $this->access->requireLinkedLearner($user, $learnerId);
        }

        return ParentPaymentAttempt::withoutGlobalScopes()->where('school_id', $user->school_id)->where('parent_user_id', $user->id)
            ->when($learnerId, fn ($query) => $query->where('learner_id', $learnerId))->latest('initiated_at')->limit(100)->get();
    }

    public function cancel(User $user, string $id): ParentPaymentAttempt
    {
        return DB::transaction(function () use ($user, $id) {
            $attempt = $this->owned($user, $id);
            $attempt = ParentPaymentAttempt::withoutGlobalScopes()->whereKey($attempt->id)->lockForUpdate()->firstOrFail();
            if (! in_array($attempt->status, ['pending', 'initiated'], true)) {
                throw ValidationException::withMessages(['status' => 'This payment request can no longer be cancelled.']);
            }
            $from = $attempt->status;
            $attempt->update(['status' => 'cancelled', 'version' => $attempt->version + 1]);
            $this->history($attempt, $from, 'cancelled', $user->id);

            return $attempt;
        });
    }

    public function history(ParentPaymentAttempt $attempt, ?string $from, string $to, ?string $actor = null, ?string $reason = null): void
    {
        ParentPaymentAttemptHistory::create(['id' => (string) Str::uuid(), 'school_id' => $attempt->school_id, 'payment_attempt_id' => $attempt->id, 'from_status' => $from, 'to_status' => $to, 'safe_reason' => $reason, 'actor_user_id' => $actor, 'created_at' => now()]);
    }

    private function account(User $user, string $learnerId, ?string $invoiceId): array
    {
        $account = LearnerFeeAccount::withoutGlobalScopes()->where('school_id', $user->school_id)->where('learner_id', $learnerId)->where('account_status', 'active')->firstOrFail();
        $invoice = $invoiceId ? FeeInvoice::withoutGlobalScopes()->whereKey($invoiceId)->where('school_id', $user->school_id)->where('learner_id', $learnerId)->whereIn('status', ['posted', 'partially_paid'])->firstOrFail() : null;
        $balance = $invoice ? $this->money->minor($invoice->balance) : max(0, $this->money->minor($account->current_balance));
        if ($balance <= 0) {
            throw ValidationException::withMessages(['amount' => 'No payable balance is available.']);
        }

        return [$account, $invoice, $balance];
    }

    private function phone(?string $verified, ?string $supplied): string
    {
        $value = preg_replace('/\D+/', '', $supplied ?: $verified ?: '');
        $value = str_starts_with($value, '0') ? '254'.substr($value, 1) : (str_starts_with($value, '7') || str_starts_with($value, '1') ? '254'.$value : $value);
        if (! preg_match('/^254[17]\d{8}$/', $value)) {
            throw ValidationException::withMessages(['phone' => 'A valid Kenyan mobile number is required.']);
        }

        return $value;
    }

    private function mask(string $phone): string
    {
        return substr($phone, 0, 3).'*****'.substr($phone, -4);
    }
}
