<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CommunicationDeliveryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'channel' => $this->channel, 'status' => $this->status, 'attempt_count' => $this->attempt_count, 'queued_at' => $this->queued_at, 'sent_at' => $this->sent_at, 'delivered_at' => $this->delivered_at];
    }
}
