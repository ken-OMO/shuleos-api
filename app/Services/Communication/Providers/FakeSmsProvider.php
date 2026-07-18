<?php

namespace App\Services\Communication\Providers;

use App\Contracts\Communication\SmsProviderInterface;
use App\Services\Communication\ProviderDeliveryResult;
use Carbon\CarbonImmutable;

class FakeSmsProvider implements SmsProviderInterface
{
    public function send(array $message): ProviderDeliveryResult
    {
        return new ProviderDeliveryResult(true, 'fake', 'fake-'.hash('sha256', $message['idempotency_key']), 'accepted', creditsUsed: $message['credits'] ?? null, acceptedAt: CarbonImmutable::now());
    }

    public function healthy(): bool
    {
        return true;
    }
}
