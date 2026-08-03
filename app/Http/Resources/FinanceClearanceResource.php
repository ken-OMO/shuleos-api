<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FinanceClearanceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'learner_id' => $this->learner_id, 'status' => $this->status, 'balance_at_decision' => $this->balance_at_decision, 'threshold' => $this->threshold, 'is_override' => (bool) $this->is_override, 'expires_at' => $this->expires_at, 'revoked_at' => $this->revoked_at, 'updated_at' => $this->updated_at];
    }
}
