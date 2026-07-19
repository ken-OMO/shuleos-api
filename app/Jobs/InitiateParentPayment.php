<?php

namespace App\Jobs;

use App\Contracts\ParentPortal\PaymentProviderInterface;
use App\Models\ParentPaymentAttempt;
use App\Models\User;
use App\Services\ParentPortal\ParentPaymentService;
use App\Services\ParentPortal\ParentPushService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class InitiateParentPayment implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public function __construct(public string $attemptId, private string $phone) {}

    public function handle(PaymentProviderInterface $provider, ParentPaymentService $payments, ParentPushService $push): void
    {
        $attempt = ParentPaymentAttempt::withoutGlobalScopes()->whereKey($this->attemptId)->first();
        if (! $attempt || $attempt->status !== 'pending') {
            return;
        }
        try {
            $result = $provider->initiate(['phone' => $this->phone, 'amount_minor' => $attempt->amount_minor, 'reference' => $attempt->payment_reference, 'idempotency_key' => $attempt->idempotency_key_hash]);
            $from = $attempt->status;
            $attempt->update([
                'status' => $result->accepted ? 'awaiting_customer' : 'failed', 'provider_request_id' => $result->providerRequestId,
                'checkout_request_id' => $result->checkoutRequestId, 'merchant_request_id' => $result->merchantRequestId,
                'accepted_at' => $result->accepted ? now() : null, 'failure_code' => $result->failureCode, 'safe_failure_message' => $result->safeMessage, 'version' => $attempt->version + 1,
            ]);
            $payments->history($attempt, $from, $attempt->status, reason: $result->safeMessage);
            if ($result->accepted) {
                $user = User::withoutGlobalScopes()->whereKey($attempt->parent_user_id)->where('school_id', $attempt->school_id)->first();
                if ($user) {
                    $push->queue($user, 'parent_payment_accepted', 'Payment request sent', 'Complete the payment request on your phone.', '/payments/'.$attempt->id, 'parent-payment:'.$attempt->id.':accepted');
                }
            }
        } catch (Throwable) {
            $attempt->update(['status' => 'failed', 'failure_code' => 'provider_unavailable', 'safe_failure_message' => 'The payment provider is temporarily unavailable.', 'version' => $attempt->version + 1]);
            $payments->history($attempt, 'pending', 'failed', reason: 'Provider unavailable.');
        }
    }
}
