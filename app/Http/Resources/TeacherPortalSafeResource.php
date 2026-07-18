<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

class TeacherPortalSafeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $data = is_array($this->resource) ? $this->resource : (method_exists($this->resource, 'toArray') ? $this->resource->toArray() : (array) $this->resource);

        return $this->sanitize($data);
    }

    private function sanitize(array $data): array
    {
        $blocked = ['school_id', 'teacher_id', 'user_id', 'parent_id', 'guardian_id', 'storage_id', 'storage_path', 'path', 'metadata', 'audit_metadata', 'safe_metadata', 'source_hash', 'stored_hash', 'requested_value_hash', 'password', 'password_hash', 'created_by', 'updated_by', 'deleted_by', 'approved_by', 'submitted_by', 'reviewed_by', 'moderated_by', 'decided_by', 'finalized_by', 'provider_message_id', 'provider_response', 'push_token', 'push_token_encrypted', 'device_identifier_hash', 'idempotency_key', 'private_teacher_notes', 'correction_reason'];

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
