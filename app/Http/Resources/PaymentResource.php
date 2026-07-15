<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PaymentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [

            'id' => $this->id,

            'learner_id' => $this->learner_id,

            'invoice_id' => $this->invoice_id,

            'payment_method_id' => $this->payment_method_id,

            'receipt_number' => $this->receipt_number,

            'amount' => $this->amount,

            'allocated_amount' => $this->allocated_amount,

            'payment_channel' => $this->payment_channel,

            'transaction_reference' => $this->transaction_reference,

            'payment_date' => $this->payment_date,

            'payment_status' => $this->payment_status,

            'reversed' => $this->reversed,

            'reversal_reason' => $this->reversal_reason,

            'reversed_at' => $this->reversed_at,

            'payer_phone' => $this->payer_phone ? str_repeat('*', max(0, strlen($this->payer_phone) - 4)).substr($this->payer_phone, -4) : null,

            'payer_name' => $this->payer_name,

            'remarks' => $this->remarks,

            'created_at' => $this->created_at,

        ];
    }
}
