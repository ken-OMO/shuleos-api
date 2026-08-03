<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AnnouncementResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'subject' => $this->subject, 'body' => $this->body, 'priority' => $this->priority, 'status' => $this->status ?? 'published', 'sent_at' => $this->sent_at, 'expires_at' => $this->expires_at, 'read_at' => $this->read_at ?? null];
    }
}
