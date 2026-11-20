<?php

namespace App\Http\Resources;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;
use JsonSerializable;
use stdClass;

class TeacherPortalSafeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $data = $this->safeValue($this->resource);

        return is_array($data) ? $data : [];
    }

    private function sanitize(array $data): array
    {
        $blocked = ['school_id', 'teacher_id', 'user_id', 'parent_id', 'guardian_id', 'storage_id', 'storage_path', 'path', 'metadata', 'audit_metadata', 'safe_metadata', 'source_hash', 'stored_hash', 'requested_value_hash', 'password', 'password_hash', 'created_by', 'updated_by', 'deleted_by', 'approved_by', 'submitted_by', 'reviewed_by', 'moderated_by', 'decided_by', 'finalized_by', 'provider_message_id', 'provider_response', 'push_token', 'push_token_encrypted', 'device_identifier_hash', 'idempotency_key', 'private_teacher_notes', 'correction_reason'];

        $blocked = array_merge($blocked, ['password_reset_token', 'mfa_secret', 'challenge_token', 'activation_token', 'challenge_nonce_hash', 'token', 'temporary_password']);

        return collect($data)->except($blocked)
            ->map(fn ($value) => $this->safeValue($value))->all();
    }

    private function safeValue(mixed $value): mixed
    {
        if ($value instanceof Collection) {
            return $value->map(fn ($item) => $this->safeValue($item))->values()->all();
        }
        if ($value instanceof Arrayable) {
            // Eloquent toArray respects hidden attributes and serializes relations.
            return $this->safeValue($value->toArray());
        }
        if ($value instanceof JsonSerializable) {
            return $this->safeValue($value->jsonSerialize());
        }
        if ($value instanceof stdClass) {
            return $this->sanitize(get_object_vars($value));
        }
        if (is_array($value)) {
            return $this->sanitize($value);
        }

        // Never cast arbitrary objects: that exposes protected/private properties.
        return is_scalar($value) || $value === null ? $value : null;
    }
}
