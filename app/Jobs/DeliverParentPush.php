<?php

namespace App\Jobs;

use App\Contracts\TeacherPortal\PushProviderInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

class DeliverParentPush implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public string $deliveryId) {}

    public function handle(PushProviderInterface $provider): void
    {
        $delivery = DB::table('parent_push_deliveries')->where('id', $this->deliveryId)->whereIn('status', ['queued', 'failed'])->first();
        if (! $delivery) {
            return;
        }
        $device = DB::table('parent_portal_devices')->where('id', $delivery->device_id)->where('user_id', $delivery->user_id)->whereNull('revoked_at')->where('push_enabled', true)->first();
        if (! $device?->push_token_encrypted) {
            DB::table('parent_push_deliveries')->where('id', $delivery->id)->update(['status' => 'skipped', 'updated_at' => now()]);

            return;
        }
        $result = $provider->send(['token' => Crypt::decryptString($device->push_token_encrypted), 'title' => $delivery->title, 'body' => $delivery->body, 'deep_link' => $delivery->deep_link, 'category' => $delivery->category, 'idempotency_key' => $delivery->idempotency_key]);
        DB::table('parent_push_deliveries')->where('id', $delivery->id)->update([
            'status' => $result->accepted ? 'accepted' : 'failed', 'provider' => $result->provider,
            'provider_message_id' => $result->messageId, 'failure_code' => $result->failureCode,
            'attempt_count' => DB::raw('attempt_count + 1'), 'sent_at' => $result->accepted ? now() : null,
            'failed_at' => $result->accepted ? null : now(), 'updated_at' => now(),
        ]);
        if ($result->invalidToken) {
            DB::table('parent_portal_devices')->where('id', $device->id)->update(['push_token_encrypted' => null, 'push_enabled' => false, 'revoked_at' => now(), 'updated_at' => now()]);
        }
    }
}
