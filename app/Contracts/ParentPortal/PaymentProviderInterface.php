<?php

namespace App\Contracts\ParentPortal;

use App\Services\ParentPortal\Payments\PaymentCallbackResult;
use App\Services\ParentPortal\Payments\PaymentInitiationResult;
use App\Services\ParentPortal\Payments\PaymentStatusResult;

interface PaymentProviderInterface
{
    public function ready(): bool;

    public function initiate(array $request): PaymentInitiationResult;

    public function parseCallback(array $payload): PaymentCallbackResult;

    public function status(string $checkoutRequestId): PaymentStatusResult;
}
