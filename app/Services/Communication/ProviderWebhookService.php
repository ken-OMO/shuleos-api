<?php

namespace App\Services\Communication;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ProviderWebhookService
{
    public function __construct(private DeliveryLifecycleService $lifecycle, private ContactHealthService $contacts) {}

    public function handleResend(Request $request): void
    {
        $raw = $request->getContent();
        abort_if(strlen($raw) > config('communication.webhook_max_bytes', 65536), 413);
        $id = (string) $request->header('svix-id');
        $timestamp = (string) $request->header('svix-timestamp');
        $signature = (string) $request->header('svix-signature');
        abort_unless($id && $timestamp && abs(now()->timestamp - (int) $timestamp) <= 300, 401);
        $secret = (string) config('communication.email.resend.webhook_secret');
        abort_unless($secret && $this->validSignature($id.'.'.$timestamp.'.'.$raw, $signature, $secret), 401);
        $payload = $request->json()->all();
        $type = (string) ($payload['type'] ?? 'unknown');
        $messageId = data_get($payload, 'data.email_id') ?: data_get($payload, 'data.id');
        $this->process('resend', $id, $type, $messageId, $raw, $this->emailStatus($type));
    }

    public function handleAfricasTalking(Request $request): void
    {
        $raw = $request->getContent();
        abort_if(strlen($raw) > config('communication.webhook_max_bytes', 65536), 413);
        $secret = (string) config('communication.sms.africas_talking.webhook_secret');
        $signature = (string) $request->header('X-Communication-Signature');
        abort_unless($secret && hash_equals(hash_hmac('sha256', $raw, $secret), $signature), 401);
        $eventId = (string) ($request->input('eventId') ?: hash('sha256', $raw));
        $messageId = (string) $request->input('id');
        $status = strtolower((string) $request->input('status'));
        $normalized = in_array($status, ['success', 'delivered'], true) ? 'delivered' : (in_array($status, ['rejected', 'failed'], true) ? 'failed' : 'sent');
        $this->process('africas_talking', $eventId, 'sms.'.$status, $messageId, $raw, $normalized);
    }

    private function process(string $provider, string $eventId, string $type, ?string $messageId, string $raw, string $status): void
    {
        DB::transaction(function () use ($provider, $eventId, $type, $messageId, $raw, $status) {
            $inserted = DB::table('communication_provider_webhook_events')->insertOrIgnore(['id' => (string) Str::uuid(), 'provider' => $provider, 'provider_event_id' => $eventId, 'event_type' => $type, 'payload_hash' => hash('sha256', $raw), 'provider_message_id' => $messageId, 'processing_status' => 'received', 'safe_metadata' => json_encode(['bytes' => strlen($raw)]), 'created_at' => now()]);
            if (! $inserted) {
                return;
            }
            $delivery = DB::table('communication_deliveries')->where('provider', $provider)->where('provider_message_id', $messageId)->lockForUpdate()->first();
            if (! $delivery) {
                DB::table('communication_provider_webhook_events')->where('provider', $provider)->where('provider_event_id', $eventId)->update(['processing_status' => 'unknown_delivery', 'processed_at' => now()]);

                return;
            }
            $this->lifecycle->transition($delivery, $status, 'webhook', $eventId);
            DB::table('communication_provider_webhook_events')->where('provider', $provider)->where('provider_event_id', $eventId)->update(['school_id' => $delivery->school_id, 'delivery_id' => $delivery->id, 'processing_status' => 'processed', 'processed_at' => now()]);
            $snapshot = DB::table('communication_recipient_snapshots')->where('communication_id', $delivery->communication_id)->where('user_id', $delivery->recipient_user_id)->where('school_id', $delivery->school_id)->first();
            if ($snapshot) {
                $event = match ($status) {
                    'bounced' => str_contains($type, 'soft') ? 'soft_bounce' : 'hard_bounce', 'complained' => 'complained', 'delivered' => 'delivered', default => null
                };
                if ($event) {
                    if ($delivery->channel === 'sms' && $delivery->destination_hash) {
                        $this->contacts->recordHash($delivery->school_id, $delivery->recipient_user_id, 'sms', $delivery->destination_hash, $event);
                    } elseif ($delivery->channel === 'email' && $snapshot->email) {
                        $this->contacts->record($delivery->school_id, $delivery->recipient_user_id, 'email', $snapshot->email, $event);
                    }
                }
            }
        });
    }

    private function emailStatus(string $type): string
    {
        return match ($type) {
            'email.sent' => 'sent', 'email.delivered' => 'delivered', 'email.opened' => 'opened', 'email.clicked' => 'clicked', 'email.bounced' => 'bounced', 'email.complained' => 'complained', 'email.failed' => 'failed', default => 'sent',
        };
    }

    private function validSignature(string $payload, string $provided, string $secret): bool
    {
        $secret = str_starts_with($secret, 'whsec_') ? substr($secret, 6) : $secret;
        $expected = base64_encode(hash_hmac('sha256', $payload, base64_decode($secret, true) ?: $secret, true));

        return collect(explode(' ', $provided))->contains(fn ($part) => hash_equals($expected, str_contains($part, ',') ? explode(',', $part, 2)[1] : $part));
    }
}
