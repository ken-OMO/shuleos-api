<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SmsWalletResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'balance_credits' => (int) $this->balance_credits, 'low_balance_threshold' => (int) $this->low_balance_threshold, 'status' => $this->status, 'updated_at' => $this->updated_at];
    }
}
