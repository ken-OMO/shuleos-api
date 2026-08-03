<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class NotificationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'title' => $this->title, 'message' => $this->message, 'state' => $this->state ?? ($this->is_read ? 'read' : 'unread'), 'is_read' => (bool) $this->is_read, 'action_url' => $this->action_url ?? null, 'read_at' => $this->read_at ?? null, 'created_at' => $this->created_at];
    }
}
