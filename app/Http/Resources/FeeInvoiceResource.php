<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FeeInvoiceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [

            'id' => $this->id,

            'school_id' => $this->school_id,

            'learner_id' => $this->learner_id,

            'academic_year_id' => $this->academic_year_id,

            'term_id' => $this->term_id,

            'grade_id' => $this->grade_id,

            'stream_id' => $this->stream_id,

            'fee_structure_id' => $this->fee_structure_id,

            'invoice_number' => $this->invoice_number,

            'total_amount' => $this->total_amount,

            'amount_paid' => $this->amount_paid,

            'balance' => $this->balance,

            'status' => $this->status,

            'invoice_date' => $this->invoice_date,

            'due_date' => $this->due_date,

            'posted_at' => $this->posted_at,

            'cancelled_at' => $this->cancelled_at,

            'generated_by' => $this->generated_by,

            'notes' => $this->notes,

            'created_at' => $this->created_at,

        ];
    }
}
