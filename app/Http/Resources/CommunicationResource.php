<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CommunicationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'communication_type' => $this->communication_type, 'category' => $this->category, 'priority' => $this->priority, 'subject' => $this->subject, 'body' => $this->body, 'status' => $this->status, 'channels' => is_string($this->channels) ? json_decode($this->channels, true) : $this->channels, 'requires_approval' => (bool) $this->requires_approval, 'risk_level' => $this->risk_level, 'scheduled_for' => $this->scheduled_for, 'expires_at' => $this->expires_at, 'sent_at' => $this->sent_at, 'recipient_count' => $this->recipient_count, 'targets' => CommunicationTargetResource::collection($this->targets ?? []), 'created_at' => $this->created_at, 'updated_at' => $this->updated_at];
    }
}
