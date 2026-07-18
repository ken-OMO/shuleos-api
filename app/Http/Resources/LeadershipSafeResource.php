<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LeadershipSafeResource extends JsonResource
{
    private const HIDDEN = [
        'school_id', 'user_id', 'actor_user_id', 'created_by', 'updated_by', 'deleted_by', 'approved_by',
        'reviewed_by', 'password', 'password_hash', 'token', 'push_token', 'push_token_encrypted',
        'device_identifier_hash', 'storage_path', 'storage_id', 'private_notes', 'confidential_notes',
        'medical_notes', 'counselling_notes', 'guardian_phone', 'guardian_email', 'provider_credentials',
        'queue_payload', 'safe_metadata',
    ];

    public function toArray(Request $request): array
    {
        return $this->sanitize(parent::toArray($request));
    }

    private function sanitize(array $data): array
    {
        foreach (self::HIDDEN as $key) {
            unset($data[$key]);
        }
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = $this->sanitize($value);
            }
        }

        return $data;
    }
}
