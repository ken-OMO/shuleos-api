<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AdministratorSafeResource extends JsonResource
{
    private const BLOCKED = ['password', 'password_hash', 'password_reset_token', 'mfa_secret', 'token', 'push_token', 'push_token_encrypted', 'storage_id', 'source_hash', 'stored_hash', 'idempotency_key_hash', 'payload', 'exception', 'old_values', 'new_values'];

    public function toArray(Request $request): array
    {
        return $this->sanitize(parent::toArray($request));
    }

    private function sanitize(array $data): array
    {
        $safe = [];
        foreach ($data as $key => $value) {
            if (in_array(strtolower((string) $key), self::BLOCKED, true) || str_contains(strtolower((string) $key), 'secret')) {
                continue;
            }
            $safe[$key] = is_array($value) ? $this->sanitize($value) : $value;
        }

        return $safe;
    }
}
