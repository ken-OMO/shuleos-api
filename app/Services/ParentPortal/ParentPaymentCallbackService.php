<?php

namespace App\Services\ParentPortal;

use App\Contracts\ParentPortal\PaymentProviderInterface;
use App\Jobs\ProcessParentPaymentCallback;
use App\Models\ParentPaymentAttempt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ParentPaymentCallbackService
{
    public function __construct(private PaymentProviderInterface $provider) {}

    public function accept(array $payload): array
    {
        $result = $this->provider->parseCallback($payload);
        if ($result->checkoutRequestId === '' || strlen($result->checkoutRequestId) > 255) {
            throw ValidationException::withMessages(['callback' => 'Invalid callback.']);
        }
        $attempt = ParentPaymentAttempt::withoutGlobalScopes()->where('provider', 'mpesa')->where('checkout_request_id', $result->checkoutRequestId)->first();
        $event = DB::table('parent_payment_callback_events')->where('provider', 'mpesa')->where('event_key', $result->eventKey)->first();
        if ($event) {
            return ['accepted' => true, 'duplicate' => true];
        }
        $id = (string) Str::uuid();
        DB::table('parent_payment_callback_events')->insert([
            'id' => $id, 'provider' => 'mpesa', 'event_key' => $result->eventKey, 'payment_attempt_id' => $attempt?->id,
            'status' => $attempt ? 'received' : 'unmatched',
            'redacted_payload' => json_encode(['checkout_fingerprint' => substr(hash('sha256', $result->checkoutRequestId), 0, 16), 'successful' => $result->successful, 'failure_code' => $result->failureCode]),
            'received_at' => now(),
        ]);
        if ($attempt) {
            ProcessParentPaymentCallback::dispatch($id, [
                'event_key' => $result->eventKey, 'checkout_request_id' => $result->checkoutRequestId, 'successful' => $result->successful,
                'amount_minor' => $result->amountMinor, 'currency' => $result->currency, 'receipt' => $result->receipt,
                'failure_code' => $result->failureCode, 'safe_message' => $result->safeMessage,
            ]);
        }

        return ['accepted' => true, 'duplicate' => false];
    }
}
