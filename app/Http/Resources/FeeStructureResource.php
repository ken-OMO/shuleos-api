<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FeeStructureResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [

            'id' => $this->id,

            'school_id' => $this->school_id,

            'academic_year_id' => $this->academic_year_id,

            'term_id' => $this->term_id,

            'grade_id' => $this->grade_id,

            'stream_id' => $this->stream_id,

            'fee_category_id' => $this->fee_category_id,

            'payment_plan_id' => $this->payment_plan_id,

            'amount' => $this->amount,

            'due_date' => $this->due_date,

            'notes' => $this->notes,

            'active' => $this->active,

            'created_at' => $this->created_at,

        ];
    }
}
