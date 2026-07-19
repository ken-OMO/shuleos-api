<?php

namespace App\Services\ParentPortal\Payments;

final readonly class PaymentStatusResult
{
    public function __construct(public string $status, public ?string $failureCode = null) {}
}
