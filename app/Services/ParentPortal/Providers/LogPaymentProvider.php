<?php

namespace App\Services\ParentPortal\Providers;

use App\Contracts\ParentPortal\PaymentProviderInterface;
use App\Services\ParentPortal\Payments\PaymentCallbackResult;
use App\Services\ParentPortal\Payments\PaymentInitiationResult;
use App\Services\ParentPortal\Payments\PaymentStatusResult;

class LogPaymentProvider implements PaymentProviderInterface
{
    public function ready(): bool
    {
        return app()->environment(['local', 'testing']);
    }

    public function initiate(array $request): PaymentInitiationResult
    {
        return new PaymentInitiationResult(true, 'log-'.str()->uuid(), 'log-'.str()->uuid(), 'log-'.str()->uuid());
    }

    public function parseCallback(array $payload): PaymentCallbackResult
    {
        return FakePaymentProvider::callback($payload);
    }

    public function status(string $checkoutRequestId): PaymentStatusResult
    {
        return new PaymentStatusResult('pending');
    }
}
