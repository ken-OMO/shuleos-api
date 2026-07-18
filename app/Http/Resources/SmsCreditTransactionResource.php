<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SmsCreditTransactionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'transaction_type' => $this->transaction_type, 'credits_delta' => (int) $this->credits_delta, 'balance_after' => (int) $this->balance_after, 'reason' => $this->reason, 'created_at' => $this->created_at];
    }
}
