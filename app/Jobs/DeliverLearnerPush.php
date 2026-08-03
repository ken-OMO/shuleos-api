<?php

namespace App\Jobs;

use App\Contracts\TeacherPortal\PushProviderInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

class DeliverLearnerPush implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public string $deliveryId) {}

    public function handle(PushProviderInterface $provider): void
    {
        $delivery = DB::table('learner_push_deliveries')->whereKey($this->deliveryId)->whereIn('status', ['queued', 'failed'])->first();
        if (! $delivery) {
            return;
        }
        $device = DB::table('learner_portal_devices')->whereKey($delivery->device_id)->where('user_id', $delivery->user_id)->whereNull('revoked_at')->where('push_enabled', true)->first();
        if (! $device || ! $device->push_token_encrypted) {
            DB::table('learner_push_deliveries')->whereKey($delivery->id)->update(['status' => 'skipped', 'updated_at' => now()]);

            return;
        }
        $result = $provider->send(['token' => Crypt::decryptString($device->push_token_encrypted), 'title' => $delivery->title, 'body' => $delivery->body, 'deep_link' => $delivery->deep_link, 'category' => $delivery->category, 'idempotency_key' => $delivery->idempotency_key]);
        DB::table('learner_push_deliveries')->whereKey($delivery->id)->update(['status' => $result->accepted ? 'accepted' : 'failed', 'provider' => $result->provider, 'provider_message_id' => $result->messageId, 'failure_code' => $result->failureCode, 'sent_at' => $result->accepted ? now() : null, 'failed_at' => $result->accepted ? null : now(), 'updated_at' => now()]);
        if ($result->invalidToken) {
            DB::table('learner_portal_devices')->whereKey($device->id)->update(['push_token_encrypted' => null, 'push_enabled' => false, 'revoked_at' => now(), 'updated_at' => now()]);
        }
    }
}
