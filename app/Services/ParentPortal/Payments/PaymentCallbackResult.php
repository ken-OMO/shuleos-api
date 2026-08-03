<?php

namespace App\Services\ParentPortal\Payments;

final readonly class PaymentCallbackResult
{
    public function __construct(
        public string $eventKey,
        public string $checkoutRequestId,
        public bool $successful,
        public ?int $amountMinor = null,
        public string $currency = 'KES',
        public ?string $receipt = null,
        public ?string $failureCode = null,
        public ?string $safeMessage = null,
    ) {}
}
