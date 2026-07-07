<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AuditLogResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        return [

            'id' => $this->id,

            'school_id' => $this->school_id,

            'user_id' => $this->user_id,

            'module' => $this->module,

            'action' => $this->action,

            'table_name' => $this->table_name,

            'record_id' => $this->record_id,

            'description' => $this->description,

            'old_values' => $this->old_values,

            'new_values' => $this->new_values,

            'ip_address' => $this->ip_address,

            'user_agent' => $this->user_agent,

            'created_at' => $this->created_at,

            'school' => $this->whenLoaded('school'),

            'user' => $this->whenLoaded('user'),

        ];
    }
}
