<?php

namespace App\Services\Communication;

use Carbon\CarbonImmutable;

final readonly class ProviderDeliveryResult
{
    public function __construct(public bool $accepted, public string $provider, public ?string $providerMessageId, public string $providerStatus, public ?string $failureCode = null, public ?string $safeFailureMessage = null, public ?int $costMinor = null, public ?int $creditsUsed = null, public ?CarbonImmutable $acceptedAt = null, public array $metadata = []) {}
}
