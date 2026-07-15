<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LearnerDiscountResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'learner_id' => $this->learner_id, 'discount_id' => $this->discount_id, 'academic_year_id' => $this->academic_year_id, 'term_id' => $this->term_id, 'fee_category_id' => $this->fee_category_id, 'status' => $this->status, 'assigned_value' => $this->assigned_value, 'starts_at' => $this->starts_at, 'ends_at' => $this->ends_at, 'created_at' => $this->created_at];
    }
}
