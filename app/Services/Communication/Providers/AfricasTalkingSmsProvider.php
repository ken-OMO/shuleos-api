<?php

namespace App\Services\Communication\Providers;

use App\Contracts\Communication\SmsProviderInterface;
use App\Services\Communication\ProviderDeliveryResult;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;

class AfricasTalkingSmsProvider implements SmsProviderInterface
{
    public function send(array $message): ProviderDeliveryResult
    {
        abort_unless($this->healthy(), 503, 'SMS provider is not configured.');
        $payload = ['username' => config('communication.sms.africas_talking.username'), 'to' => $message['to'], 'message' => $message['text']];
        if (filled(config('communication.sms.africas_talking.sender_id'))) {
            $payload['from'] = config('communication.sms.africas_talking.sender_id');
        }
        $response = Http::withHeaders(['apiKey' => config('communication.sms.africas_talking.api_key'), 'Idempotency-Key' => $message['idempotency_key']])
            ->asForm()->timeout(config('communication.email.timeout', 10))
            ->post(rtrim(config('communication.sms.africas_talking.base_url'), '/').'/version1/messaging', $payload);
        $recipient = $response->json('SMSMessageData.Recipients.0');
        $accepted = $response->successful() && in_array((string) data_get($recipient, 'statusCode'), ['100', '101', '102'], true);

        return new ProviderDeliveryResult($accepted, 'africas_talking', data_get($recipient, 'messageId'), $accepted ? 'accepted' : 'failed', $accepted ? null : ($response->serverError() ? 'temporary_provider_failure' : 'provider_rejected'), $accepted ? null : 'SMS provider rejected the request.', creditsUsed: $message['credits'] ?? null, acceptedAt: $accepted ? CarbonImmutable::now() : null);
    }

    public function healthy(): bool
    {
        return filled(config('communication.sms.africas_talking.username')) && filled(config('communication.sms.africas_talking.api_key')) && filled(config('communication.sms.africas_talking.base_url'));
    }
}
