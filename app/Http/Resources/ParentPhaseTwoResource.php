<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ParentPhaseTwoResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $data = is_array($this->resource) ? $this->resource : $this->resource->toArray();

        return $this->sanitize($data);
    }

    protected function sanitize(array $data): array
    {
        $blocked = ['school_id', 'parent_user_id', 'user_id', 'resolved_staff_user_id', 'sender_user_id', 'storage_id', 'safe_filename', 'source_hash', 'stored_hash', 'idempotency_key_hash', 'phone_hash', 'provider_request_id', 'checkout_request_id', 'merchant_request_id', 'provider_message_id', 'meeting_link_encrypted', 'safe_server_state', 'safe_metadata'];

        return collect($data)->except($blocked)->map(fn ($value) => is_array($value) ? $this->sanitize($value) : $value)->all();
    }
}
