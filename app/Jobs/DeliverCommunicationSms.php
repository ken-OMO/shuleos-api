<?php

namespace App\Jobs;

use App\Contracts\Communication\SmsProviderInterface;
use App\Services\Communication\DeliveryLifecycleService;
use App\Services\Communication\SmsWalletService;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class DeliverCommunicationSms implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [30, 120, 600];

    public function __construct(public string $deliveryId) {}

    public function handle(SmsProviderInterface $provider, SmsWalletService $wallet, DeliveryLifecycleService $lifecycle): void
    {
        $delivery = DB::table('communication_deliveries')->where('id', $this->deliveryId)->where('channel', 'sms')->whereIn('status', ['queued', 'failed'])->first();
        if (! $delivery || $delivery->provider_message_id) {
            return;
        }
        $communication = DB::table('communications')->where('id', $delivery->communication_id)->where('school_id', $delivery->school_id)->whereIn('status', ['sent', 'partially_failed'])->first();
        if (! $communication) {
            return;
        }
        try {
            $phone = Crypt::decryptString($delivery->destination_encrypted);
        } catch (DecryptException) {
            $wallet->refund($delivery->id);
            $lifecycle->transition($delivery, 'failed', 'job', reason: 'Destination unavailable.');

            return;
        }
        $lifecycle->transition($delivery, 'sending', 'job');
        DB::table('communication_deliveries')->where('id', $delivery->id)->update(['attempt_count' => DB::raw('attempt_count + 1'), 'updated_at' => now()]);
        $usage = DB::table('sms_usage_records')->where('delivery_id', $delivery->id)->firstOrFail();
        $result = $provider->send(['to' => $phone, 'text' => $communication->body, 'idempotency_key' => $delivery->delivery_key, 'credits' => $usage->credits_reserved]);
        DB::table('communication_deliveries')->where('id', $delivery->id)->update(['provider' => $result->provider, 'provider_message_id' => $result->providerMessageId, 'provider_status' => $result->providerStatus, 'failure_code' => $result->failureCode, 'failure_reason' => $result->safeFailureMessage, 'cost_minor' => $usage->cost_minor, 'credits_used' => $usage->credits_reserved, 'segment_count' => $usage->segment_count, 'accepted_at' => $result->acceptedAt, 'updated_at' => now()]);
        $current = DB::table('communication_deliveries')->where('id', $delivery->id)->first();
        if ($result->accepted) {
            $wallet->consume($delivery->id);
            $lifecycle->transition($current, 'accepted', 'provider');

            return;
        }
        if ($result->failureCode === 'temporary_provider_failure') {
            DB::table('communication_deliveries')->where('id', $delivery->id)->update(['status' => 'queued', 'failure_code' => 'temporary_provider_failure', 'failure_reason' => $result->safeFailureMessage, 'updated_at' => now()]);
            throw new RuntimeException($result->safeFailureMessage ?: 'Temporary SMS provider failure.');
        }
        $wallet->refund($delivery->id);
        $lifecycle->transition($current, 'failed', 'provider', reason: $result->safeFailureMessage);
        DB::table('communications')->where('id', $communication->id)->where('status', 'sent')->update(['status' => 'partially_failed', 'updated_at' => now()]);
    }

    public function failed(?\Throwable $exception): void
    {
        $delivery = DB::table('communication_deliveries')->where('id', $this->deliveryId)->first();
        if (! $delivery) {
            return;
        }
        app(SmsWalletService::class)->refund($delivery->id);
        DB::table('communication_deliveries')->where('id', $delivery->id)->update(['status' => 'failed', 'failure_code' => 'retry_exhausted', 'failure_reason' => mb_substr($exception?->getMessage() ?: 'SMS retries exhausted.', 0, 500), 'updated_at' => now()]);
        DB::table('communications')->where('id', $delivery->communication_id)->whereIn('status', ['sent', 'sending'])->update(['status' => 'partially_failed', 'updated_at' => now()]);
    }
}
