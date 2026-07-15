<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FeeInvoiceItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'fee_category_id' => $this->fee_category_id,
            'amount' => $this->amount,
            'notes' => $this->notes,
        ];
    }
}
