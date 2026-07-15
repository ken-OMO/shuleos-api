<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AppliedDiscountResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'invoice_id' => $this->invoice_id, 'discount_id' => $this->discount_id, 'eligible_amount' => $this->eligible_amount, 'discount_amount' => $this->discount_amount, 'status' => $this->status, 'applied_at' => $this->applied_at, 'reversed_at' => $this->reversed_at];
    }
}
