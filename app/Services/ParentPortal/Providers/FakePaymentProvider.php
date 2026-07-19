<?php

namespace App\Services\ParentPortal\Providers;

use App\Contracts\ParentPortal\PaymentProviderInterface;
use App\Services\ParentPortal\Payments\PaymentCallbackResult;
use App\Services\ParentPortal\Payments\PaymentInitiationResult;
use App\Services\ParentPortal\Payments\PaymentStatusResult;

class FakePaymentProvider implements PaymentProviderInterface
{
    public function ready(): bool
    {
        return true;
    }

    public function initiate(array $request): PaymentInitiationResult
    {
        $key = substr(hash('sha256', $request['idempotency_key']), 0, 24);

        return new PaymentInitiationResult(true, 'fake-request-'.$key, 'fake-checkout-'.$key, 'fake-merchant-'.$key);
    }

    public function parseCallback(array $payload): PaymentCallbackResult
    {
        return self::callback($payload);
    }

    public static function callback(array $payload): PaymentCallbackResult
    {
        return new PaymentCallbackResult(
            (string) ($payload['event_key'] ?? hash('sha256', json_encode($payload))),
            (string) ($payload['checkout_request_id'] ?? ''),
            (bool) ($payload['successful'] ?? false),
            isset($payload['amount_minor']) ? (int) $payload['amount_minor'] : null,
            (string) ($payload['currency'] ?? 'KES'),
            $payload['receipt'] ?? null,
            $payload['failure_code'] ?? null,
            $payload['safe_message'] ?? null,
        );
    }

    public function status(string $checkoutRequestId): PaymentStatusResult
    {
        return new PaymentStatusResult('pending');
    }
}
