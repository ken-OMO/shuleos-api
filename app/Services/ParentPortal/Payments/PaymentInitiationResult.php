<?php

namespace App\Services\ParentPortal\Payments;

final readonly class PaymentInitiationResult
{
    public function __construct(
        public bool $accepted,
        public ?string $providerRequestId = null,
        public ?string $checkoutRequestId = null,
        public ?string $merchantRequestId = null,
        public ?string $failureCode = null,
        public ?string $safeMessage = null,
    ) {}
}
