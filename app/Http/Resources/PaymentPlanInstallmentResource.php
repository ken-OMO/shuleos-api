<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PaymentPlanInstallmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'installment_name' => $this->installment_name, 'installment_order' => $this->installment_order, 'scheduled_amount' => $this->scheduled_amount, 'paid_amount' => $this->paid_amount, 'outstanding_amount' => $this->outstanding_amount, 'due_date' => $this->due_date, 'status' => $this->status];
    }
}
