<?php

namespace App\Services\ParentPortal;

use App\Models\LearnerFeeAccount;
use App\Models\ParentPaymentAttempt;
use App\Models\User;
use App\Services\Finance\FinanceMoney;
use App\Services\Finance\FinanceNotificationService;
use App\Services\Finance\FinancePaymentService;
use Illuminate\Support\Facades\DB;
use Throwable;

class ParentPaymentProcessingService
{
    public function __construct(
        private FinancePaymentService $finance,
        private FinanceMoney $money,
        private ParentPaymentService $attempts,
        private FinanceNotificationService $notifications,
        private ParentPushService $push,
    ) {}

    public function process(string $attemptId, array $callback): void
    {
        $attempt = ParentPaymentAttempt::withoutGlobalScopes()->whereKey($attemptId)->firstOrFail();
        if (in_array($attempt->status, ['completed', 'failed', 'cancelled', 'expired'], true)) {
            return;
        }
        if (! $callback['successful']) {
            DB::transaction(function () use ($attemptId, $callback) {
                $locked = ParentPaymentAttempt::withoutGlobalScopes()->whereKey($attemptId)->lockForUpdate()->firstOrFail();
                if ($locked->status === 'completed') {
                    return;
                }
                $from = $locked->status;
                $locked->update(['status' => 'failed', 'failure_code' => $callback['failure_code'] ?: 'provider_failed', 'safe_failure_message' => $callback['safe_message'] ?: 'The payment was not completed.', 'version' => $locked->version + 1]);
                $this->attempts->history($locked, $from, 'failed', reason: $locked->safe_failure_message);
            });
            $this->notify($attemptId, false);

            return;
        }
        if ((int) $callback['amount_minor'] !== $attempt->amount_minor || ($callback['currency'] ?? 'KES') !== $attempt->currency || blank($callback['receipt'])) {
            $this->reconciliation($attemptId, 'Payment confirmation did not match the stored request.');

            return;
        }

        DB::transaction(function () use ($attemptId, $callback) {
            $attempt = ParentPaymentAttempt::withoutGlobalScopes()->whereKey($attemptId)->lockForUpdate()->firstOrFail();
            if ($attempt->status !== 'completed') {
                $attempt->update(['status' => 'processing', 'provider_receipt' => $callback['receipt'], 'confirmed_amount_minor' => $callback['amount_minor'], 'confirmed_currency' => $callback['currency'] ?? 'KES', 'version' => $attempt->version + 1]);
            }
        });

        try {
            DB::transaction(function () use ($attemptId, $callback) {
                $attempt = ParentPaymentAttempt::withoutGlobalScopes()->whereKey($attemptId)->lockForUpdate()->firstOrFail();
                if ($attempt->status === 'completed') {
                    return;
                }
                $user = User::withoutGlobalScopes()->whereKey($attempt->parent_user_id)->where('school_id', $attempt->school_id)->firstOrFail();
                $account = LearnerFeeAccount::withoutGlobalScopes()->where('school_id', $attempt->school_id)->where('learner_id', $attempt->learner_id)->where('account_status', 'active')->firstOrFail();
                $payment = $this->finance->createVerifiedProviderPayment($user, $account, [
                    'amount' => $this->money->decimal($attempt->amount_minor), 'transaction_reference' => $callback['receipt'],
                    'provider' => $attempt->provider,
                    'payer_phone' => $attempt->phone_masked, 'payer_name' => 'Parent portal',
                ]);
                if ($payment->payment_status === 'confirmed') {
                    $this->finance->autoAllocate($user, $payment->id);
                }
                $from = $attempt->status;
                $attempt->update(['status' => 'completed', 'payment_id' => $payment->id, 'completed_at' => now(), 'failure_code' => null, 'safe_failure_message' => null, 'version' => $attempt->version + 1]);
                $this->attempts->history($attempt, $from, 'completed');
                DB::afterCommit(fn () => $this->notify($attempt->id, true));
            });
        } catch (Throwable $exception) {
            report($exception);
            $this->reconciliation($attemptId, 'Provider confirmed payment but finance posting requires review.');
        }
    }

    private function notify(string $attemptId, bool $successful): void
    {
        $attempt = ParentPaymentAttempt::withoutGlobalScopes()->whereKey($attemptId)->first();
        if (! $attempt) {
            return;
        }
        $user = User::withoutGlobalScopes()->whereKey($attempt->parent_user_id)->where('school_id', $attempt->school_id)->first();
        if (! $user) {
            return;
        }
        $type = $successful ? 'parent_payment_completed' : 'parent_payment_failed';
        $title = $successful ? 'Payment completed' : 'Payment needs attention';
        $message = $successful ? 'Your payment was completed and a receipt is ready.' : 'Your payment was not completed. Review the payment request for details.';
        $this->notifications->forLearner($attempt->school_id, $attempt->learner_id, $type, 'parent-payment:'.$attempt->id.':'.$attempt->status, $title, $message);
        $this->push->queue($user, $type, $title, $message, '/payments/'.$attempt->id, 'parent-payment:'.$attempt->id.':'.$attempt->status);
    }

    private function reconciliation(string $id, string $message): void
    {
        DB::transaction(function () use ($id, $message) {
            $attempt = ParentPaymentAttempt::withoutGlobalScopes()->whereKey($id)->lockForUpdate()->firstOrFail();
            if ($attempt->status === 'completed') {
                return;
            }
            $from = $attempt->status;
            $attempt->update(['status' => 'reconciliation_required', 'failure_code' => 'reconciliation_required', 'safe_failure_message' => $message, 'version' => $attempt->version + 1]);
            $this->attempts->history($attempt, $from, 'reconciliation_required', reason: $message);
        });
    }
}
