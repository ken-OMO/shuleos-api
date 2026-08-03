<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CommunicationTemplateResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'name' => $this->name, 'category' => $this->category, 'subject' => $this->subject, 'body' => $this->body, 'version' => $this->version, 'is_system' => (bool) $this->is_system, 'active' => (bool) $this->active];
    }
}
