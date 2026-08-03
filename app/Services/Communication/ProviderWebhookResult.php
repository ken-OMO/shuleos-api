<?php

namespace App\Services\Communication;

final readonly class ProviderWebhookResult
{
    public function __construct(public string $eventId, public string $eventType, public ?string $providerMessageId, public string $normalizedStatus, public array $safeMetadata = []) {}
}
