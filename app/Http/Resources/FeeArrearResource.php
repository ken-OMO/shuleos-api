<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FeeArrearResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'learner_id' => $this->learner_id, 'academic_year_id' => $this->academic_year_id, 'term_id' => $this->term_id, 'amount' => $this->amount, 'carried_forward_amount' => $this->carried_forward_amount, 'status' => $this->status, 'calculated_at' => $this->calculated_at, 'resolved_at' => $this->resolved_at];
    }
}
