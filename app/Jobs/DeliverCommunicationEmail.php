<?php

namespace App\Jobs;

use App\Contracts\Communication\EmailProviderInterface;
use App\Services\Communication\CommunicationBrandingService;
use App\Services\Communication\ContactHealthService;
use App\Services\Communication\DeliveryLifecycleService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class DeliverCommunicationEmail implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [30, 120, 600];

    public function __construct(public string $deliveryId) {}

    public function handle(EmailProviderInterface $provider, CommunicationBrandingService $branding, ContactHealthService $contacts, DeliveryLifecycleService $lifecycle): void
    {
        $delivery = DB::table('communication_deliveries')->where('id', $this->deliveryId)->whereIn('status', ['queued', 'failed'])->first();
        if (! $delivery) {
            return;
        }
        $communication = DB::table('communications')->where('id', $delivery->communication_id)->where('school_id', $delivery->school_id)->whereIn('status', ['queued', 'sending', 'sent', 'partially_failed'])->first();
        $recipient = DB::table('communication_recipient_snapshots')->where('communication_id', $delivery->communication_id)->where('user_id', $delivery->recipient_user_id)->where('school_id', $delivery->school_id)->first();
        if (! $communication || ! $recipient || ! $recipient->email_valid || $contacts->suppressed($delivery->school_id, $delivery->recipient_user_id, 'email', $recipient->email)) {
            DB::table('communication_deliveries')->where('id', $delivery->id)->update(['status' => 'skipped', 'failure_reason' => 'Email unavailable.', 'updated_at' => now()]);

            return;
        }
        if ($delivery->provider_message_id) {
            return;
        }
        $lifecycle->transition($delivery, 'sending', 'job');
        DB::table('communication_deliveries')->where('id', $delivery->id)->update(['attempt_count' => DB::raw('attempt_count + 1'), 'updated_at' => now()]);
        try {
            $envelope = $branding->emailEnvelope($delivery->school_id);
            $result = $provider->send(['from' => $envelope['from'], 'reply_to' => $envelope['reply_to'], 'to' => [$recipient->email], 'subject' => $communication->subject, 'text' => $communication->body, 'idempotency_key' => $delivery->delivery_key]);
            DB::table('communication_deliveries')->where('id', $delivery->id)->update(['provider' => $result->provider, 'provider_message_id' => $result->providerMessageId, 'provider_status' => $result->providerStatus, 'failure_code' => $result->failureCode, 'failure_reason' => $result->safeFailureMessage, 'accepted_at' => $result->acceptedAt, 'updated_at' => now()]);
            $current = DB::table('communication_deliveries')->where('id', $delivery->id)->first();
            if ($result->accepted) {
                $lifecycle->transition($current, 'accepted', 'provider');
            } elseif ($result->failureCode === 'temporary_provider_failure') {
                throw new RuntimeException($result->safeFailureMessage ?: 'Temporary email provider failure.');
            } else {
                $lifecycle->transition($current, 'failed', 'provider', reason: $result->safeFailureMessage);
                DB::table('communications')->where('id', $communication->id)->where('status', 'sent')->update(['status' => 'partially_failed', 'updated_at' => now()]);
            }
        } catch (\Throwable $exception) {
            DB::table('communication_deliveries')->where('id', $delivery->id)->update(['status' => 'queued', 'failure_code' => 'temporary_provider_failure', 'failure_reason' => mb_substr($exception->getMessage(), 0, 500), 'updated_at' => now()]);
            DB::table('communications')->where('id', $communication->id)->where('status', 'sent')->update(['status' => 'partially_failed', 'updated_at' => now()]);
            throw $exception;
        }
    }

    public function failed(?\Throwable $exception): void
    {
        $delivery = DB::table('communication_deliveries')->where('id', $this->deliveryId)->first();
        if (! $delivery) {
            return;
        }
        DB::table('communication_deliveries')->where('id', $delivery->id)->update(['status' => 'failed', 'failure_code' => 'retry_exhausted', 'failure_reason' => mb_substr($exception?->getMessage() ?: 'Email retries exhausted.', 0, 500), 'updated_at' => now()]);
        DB::table('communications')->where('id', $delivery->communication_id)->whereIn('status', ['sent', 'sending'])->update(['status' => 'partially_failed', 'updated_at' => now()]);
    }
}
