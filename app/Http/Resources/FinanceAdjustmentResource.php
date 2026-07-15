<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FinanceAdjustmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'learner_id' => $this->learner_id, 'adjustment_type' => $this->adjustment_type, 'direction' => $this->direction, 'amount' => $this->amount, 'reason' => $this->reason, 'status' => $this->status, 'submitted_at' => $this->submitted_at, 'approved_at' => $this->approved_at, 'posted_at' => $this->posted_at, 'reversed_at' => $this->reversed_at];
    }
}
