<?php

namespace App\Services\Communication\Providers;

use App\Contracts\Communication\EmailProviderInterface;
use App\Services\Communication\ProviderDeliveryResult;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;

class ResendEmailProvider implements EmailProviderInterface
{
    public function send(array $message): ProviderDeliveryResult
    {
        abort_unless($this->healthy(), 503, 'Email provider is not configured.');
        $response = Http::withToken(config('communication.email.resend.api_key'))
            ->acceptJson()
            ->asJson()
            ->timeout(config('communication.email.timeout', 10))
            ->withHeaders(['Idempotency-Key' => $message['idempotency_key']])
            ->post(rtrim(config('communication.email.resend.base_url'), '/').'/emails', collect($message)->only(['from', 'to', 'subject', 'text', 'html', 'reply_to'])->filter()->all());

        if ($response->successful() && $response->json('id')) {
            return new ProviderDeliveryResult(true, 'resend', (string) $response->json('id'), 'accepted', acceptedAt: CarbonImmutable::now());
        }

        $temporary = $response->serverError() || $response->status() === 429;

        return new ProviderDeliveryResult(false, 'resend', null, 'failed', $temporary ? 'temporary_provider_failure' : 'provider_rejected', 'Email provider rejected the request.');
    }

    public function healthy(): bool
    {
        return filled(config('communication.email.resend.api_key')) && filled(config('communication.email.resend.base_url'));
    }
}
