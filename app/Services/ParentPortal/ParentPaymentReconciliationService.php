<?php

namespace App\Services\ParentPortal;

use App\Contracts\ParentPortal\PaymentProviderInterface;
use App\Models\ParentPaymentAttempt;

class ParentPaymentReconciliationService
{
    public function __construct(private ParentPaymentProcessingService $processing, private PaymentProviderInterface $provider) {}

    public function expire(int $limit = 100): int
    {
        $query = ParentPaymentAttempt::withoutGlobalScopes()->whereIn('status', ['pending', 'initiated', 'awaiting_customer'])
            ->where('initiated_at', '<', now()->subMinutes(config('parent_portal_phase_two.attempt_expiry_minutes', 30)))->limit(min($limit, 500));
        $count = 0;
        foreach ($query->get() as $attempt) {
            $attempt->update(['status' => 'expired', 'expired_at' => now(), 'failure_code' => 'expired', 'safe_failure_message' => 'The payment request expired.', 'version' => $attempt->version + 1]);
            $count++;
        }

        return $count;
    }

    public function pending(int $limit = 100): int
    {
        $count = 0;
        ParentPaymentAttempt::withoutGlobalScopes()->join('schools', 'schools.id', '=', 'parent_payment_attempts.school_id')
            ->where('schools.active', true)->where('parent_payment_attempts.provider', config('parent_portal_phase_two.payment_provider'))
            ->whereIn('parent_payment_attempts.status', ['awaiting_customer', 'processing'])->whereNotNull('parent_payment_attempts.checkout_request_id')
            ->select('parent_payment_attempts.*')->limit(min($limit, 500))->get()->each(function ($attempt) use (&$count) {
                $status = $this->provider->status($attempt->checkout_request_id);
                if ($status->status === 'successful') {
                    $attempt->update(['status' => 'reconciliation_required', 'failure_code' => 'provider_success_without_callback', 'safe_failure_message' => 'Provider success requires finance reconciliation.', 'version' => $attempt->version + 1]);
                    $count++;
                } elseif ($status->status === 'failed') {
                    $attempt->update(['status' => 'failed', 'failure_code' => $status->failureCode ?: 'provider_failed', 'safe_failure_message' => 'The payment was not completed.', 'version' => $attempt->version + 1]);
                    $count++;
                }
            });

        return $count;
    }

    public function retryPosting(int $limit = 100): int
    {
        $count = 0;
        ParentPaymentAttempt::withoutGlobalScopes()->where('status', 'reconciliation_required')->whereNotNull('provider_receipt')
            ->whereNotNull('confirmed_amount_minor')->limit(min($limit, 500))->get()->each(function ($attempt) use (&$count) {
                $this->processing->process($attempt->id, ['successful' => true, 'amount_minor' => $attempt->confirmed_amount_minor, 'currency' => $attempt->confirmed_currency, 'receipt' => $attempt->provider_receipt]);
                $count++;
            });

        return $count;
    }
}
