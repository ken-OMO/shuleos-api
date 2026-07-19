<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LearnerPortalSafeResource extends JsonResource
{
    private const HIDDEN = ['school_id', 'learner_id', 'user_id', 'created_by', 'updated_by', 'deleted_by', 'teacher_id', 'guardian_id', 'storage_id', 'storage_path', 'source_hash', 'stored_hash', 'device_identifier_hash', 'push_token_encrypted', 'provider_message_id', 'failure_code', 'private_teacher_notes', 'moderation_notes', 'safe_server_record', 'safe_metadata', 'destination_role'];

    public function toArray(Request $request): array
    {
        return $this->sanitize(parent::toArray($request));
    }

    private function sanitize(array $data): array
    {
        foreach (self::HIDDEN as $key) {
            unset($data[$key]);
        } foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = $this->sanitize($value);
            }
        }

        return $data;
    }
}
