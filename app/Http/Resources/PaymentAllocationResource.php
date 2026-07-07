<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PaymentAllocationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [

            'id' => $this->id,

            'school_id' => $this->school_id,

            'payment_id' => $this->payment_id,

            'invoice_id' => $this->invoice_id,

            'allocated_amount' => $this->allocated_amount,

            'created_by' => $this->created_by,

            'created_at' => $this->created_at,

        ];
    }
}
