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

            'school_id' => $this->school_id,

            'learner_id' => $this->learner_id,

            'invoice_id' => $this->invoice_id,

            'payment_method_id' => $this->payment_method_id,

            'receipt_number' => $this->receipt_number,

            'amount' => $this->amount,

            'allocated_amount' => $this->allocated_amount,

            'payment_channel' => $this->payment_channel,

            'transaction_reference' => $this->transaction_reference,

            'payment_date' => $this->payment_date,

            'received_by' => $this->received_by,

            'payment_status' => $this->payment_status,

            'reversed' => $this->reversed,

            'reversal_reason' => $this->reversal_reason,

            'reversed_at' => $this->reversed_at,

            'reversed_by' => $this->reversed_by,

            'payer_phone' => $this->payer_phone,

            'payer_name' => $this->payer_name,

            'remarks' => $this->remarks,

            'posted_by' => $this->posted_by,

            'created_at' => $this->created_at,

        ];
    }
}
