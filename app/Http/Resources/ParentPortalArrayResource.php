<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

class ParentPortalArrayResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return $this->sanitize(is_array($this->resource) ? $this->resource : (array) $this->resource);
    }

    private function sanitize(array $data): array
    {
        $blocked = ['school_id', 'parent_id', 'guardian_id', 'user_id', 'storage_id', 'storage_path', 'path', 'audit_metadata', 'metadata', 'created_by', 'updated_by', 'deleted_by', 'approved_by', 'published_by', 'received_by', 'password', 'password_hash', 'push_token', 'push_token_encrypted', 'device_identifier_hash', 'provider_message_id', 'provider_response'];

        return collect($data)->except($blocked)->map(function ($value) {
            if (is_array($value)) {
                return $this->sanitize($value);
            }
            if ($value instanceof Collection) {
                return $value->map(fn ($item) => is_array($item) || is_object($item) ? $this->sanitize((array) $item) : $item)->values();
            }

            return $value;
        })->all();
    }
}
