<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LearnerFeeAccountResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [

            'id' => $this->id,

            'learner_id' => $this->learner_id,

            'account_number' => $this->account_number,

            'current_balance' => $this->current_balance,

            'credit_limit' => $this->credit_limit,

            'last_payment_date' => $this->last_payment_date,

            'account_status' => $this->account_status,

            'active' => $this->active,

            'created_at' => $this->created_at,

        ];
    }
}
