<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FeeDiscountResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'discount_name' => $this->discount_name, 'description' => $this->description, 'discount_type' => $this->discount_type, 'discount_value' => $this->discount_value, 'maximum_discount' => $this->maximum_discount, 'grade_id' => $this->grade_id, 'stream_id' => $this->stream_id, 'academic_year_id' => $this->academic_year_id, 'term_id' => $this->term_id, 'effective_from' => $this->effective_from, 'effective_to' => $this->effective_to, 'status' => $this->status, 'revision' => $this->revision, 'fee_category_ids' => $this->fee_category_ids ?? []];
    }
}
