<?php

namespace App\Services\Communication;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class DeliveryLifecycleService
{
    private const ORDER = ['pending' => 0, 'queued' => 1, 'sending' => 2, 'accepted' => 3, 'sent' => 4, 'delivered' => 5, 'opened' => 6, 'clicked' => 7];

    private const TERMINAL = ['bounced', 'complained', 'failed', 'skipped', 'cancelled', 'expired'];

    public function transition(object $delivery, string $status, string $source, ?string $eventId = null, ?string $reason = null): bool
    {
        $current = $delivery->status;
        if ($current === $status || in_array($current, self::TERMINAL, true) || (isset(self::ORDER[$current], self::ORDER[$status]) && self::ORDER[$status] < self::ORDER[$current])) {
            return false;
        }
        DB::table('communication_deliveries')->where('id', $delivery->id)->where('status', $current)->update(['status' => $status, 'last_provider_event_at' => now(), 'updated_at' => now()] + match ($status) {
            'accepted' => ['accepted_at' => now()],
            'sent' => ['sent_at' => now()],
            'delivered' => ['delivered_at' => now()],
            default => [],
        });
        DB::table('communication_delivery_status_history')->insert(['id' => (string) Str::uuid(), 'school_id' => $delivery->school_id, 'delivery_id' => $delivery->id, 'from_status' => $current, 'to_status' => $status, 'source' => $source, 'provider_event_id' => $eventId, 'safe_reason' => $reason ? mb_substr($reason, 0, 500) : null, 'created_at' => now()]);

        return true;
    }
}
