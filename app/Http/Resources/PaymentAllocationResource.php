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

            'payment_id' => $this->payment_id,

            'invoice_id' => $this->invoice_id,

            'allocated_amount' => $this->allocated_amount,

            'status' => $this->status,

            'reversed_at' => $this->reversed_at,

            'created_at' => $this->created_at,

        ];
    }
}
