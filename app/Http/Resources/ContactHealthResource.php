<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ContactHealthResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'user_id' => $this->user_id, 'channel' => $this->channel, 'status' => $this->status, 'reason' => $this->reason, 'hard_bounce_count' => $this->hard_bounce_count, 'soft_bounce_count' => $this->soft_bounce_count, 'complaint_count' => $this->complaint_count, 'last_success_at' => $this->last_success_at, 'last_failure_at' => $this->last_failure_at, 'suppressed_at' => $this->suppressed_at, 'restored_at' => $this->restored_at];
    }
}
