<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

class ParentCommunicationResource extends ParentPortalArrayResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'subject' => $this->subject,
            'body' => $this->body,
            'priority' => $this->priority,
            'category' => $this->category,
            'sent_at' => $this->sent_at,
            'read_state' => $this->state ?? ($this->read_at ? 'read' : 'unread'),
            'sender_display_name' => trim(($this->sender_first_name ?? '').' '.($this->sender_last_name ?? '')) ?: ($this->branding_sender_name ?? $this->school_name),
            'school' => ['name' => $this->school_name],
            'deep_link' => '/parent/communications/'.$this->id,
        ];
    }
}
