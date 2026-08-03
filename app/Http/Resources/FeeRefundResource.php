<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FeeRefundResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'learner_id' => $this->learner_id, 'payment_id' => $this->payment_id, 'refund_amount' => $this->refund_amount, 'reason' => $this->reason, 'status' => $this->status, 'refund_date' => $this->refund_date, 'requested_at' => $this->requested_at, 'approved_at' => $this->approved_at, 'processed_at' => $this->processed_at];
    }
}
