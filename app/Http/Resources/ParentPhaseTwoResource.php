<?php

namespace App\Http\Resources;

use App\Models\ReportCard;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

class ParentPhaseTwoResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $data = is_array($this->resource) ? $this->resource : $this->resource->toArray();

        return $this->sanitize($data);
    }

    protected function sanitize(array $data): array
    {
        if (($data['report_card'] ?? null) instanceof ReportCard
            && isset($data['access'])) {
            return $this->sanitize(
                (new ParentReportCardResource($data))->resolve(request())
            );
        }

        $blocked = ['school_id', 'parent_user_id', 'user_id', 'resolved_staff_user_id', 'sender_user_id', 'storage_id', 'safe_filename', 'source_hash', 'stored_hash', 'idempotency_key_hash', 'phone_hash', 'provider_request_id', 'checkout_request_id', 'merchant_request_id', 'provider_message_id', 'meeting_link_encrypted', 'safe_server_state', 'safe_metadata'];

        return collect($data)->except($blocked)
            ->map(fn ($value) => $this->sanitizeValue($value))->all();
    }

    private function sanitizeValue(mixed $value): mixed
    {
        if (is_array($value)) {
            return $this->sanitize($value);
        }

        if ($value instanceof Collection) {
            return $value->map(fn ($item) => $this->sanitizeValue($item))->all();
        }

        return $value;
    }
}
