<?php

namespace App\Services\Communication;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CommunicationInAppChannel
{
    public function deliver(object $communication, object $recipient, string $deliveryId): void
    {
        $key = 'communication:'.$communication->id.':user:'.$recipient->user_id;
        DB::table('notifications')->insertOrIgnore(['id' => (string) Str::uuid(), 'school_id' => $communication->school_id, 'user_id' => $recipient->user_id, 'communication_id' => $communication->id, 'delivery_id' => $deliveryId, 'notification_key' => $key, 'notification_type' => 'communication', 'title' => $communication->subject, 'message' => mb_substr($communication->body, 0, 240), 'action_url' => '/communications/'.$communication->id, 'state' => 'unread', 'is_read' => false, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('communication_deliveries')->where('id', $deliveryId)->update(['status' => 'delivered', 'sent_at' => now(), 'delivered_at' => now(), 'updated_at' => now()]);
    }
}
