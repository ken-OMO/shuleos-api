<?php

namespace App\Services\Communication\Providers;

use App\Contracts\Communication\EmailProviderInterface;
use App\Services\Communication\ProviderDeliveryResult;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;

class LogEmailProvider implements EmailProviderInterface
{
    public function send(array $message): ProviderDeliveryResult
    {
        Log::info('Communication email accepted by log provider.', ['delivery_key' => $message['idempotency_key']]);

        return new ProviderDeliveryResult(true, 'log', 'log-'.hash('sha256', $message['idempotency_key']), 'accepted', acceptedAt: CarbonImmutable::now());
    }

    public function healthy(): bool
    {
        return true;
    }
}
