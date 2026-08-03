<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

class ParentPaymentAttemptResource extends ParentPhaseTwoResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id, 'reference' => $this->payment_reference, 'learner_id' => $this->learner_id,
            'invoice_id' => $this->invoice_id, 'amount' => number_format($this->amount_minor / 100, 2, '.', ''),
            'currency' => $this->currency, 'status' => $this->status, 'phone_masked' => $this->phone_masked,
            'provider' => strtoupper($this->provider), 'safe_failure_reason' => $this->safe_failure_message,
            'initiated_at' => $this->initiated_at?->toIso8601String(), 'completed_at' => $this->completed_at?->toIso8601String(),
            'receipt_number' => $this->whenLoaded('payment', fn () => $this->payment?->receipt_number),
            'allocated_amount' => $this->whenLoaded('payment', fn () => $this->payment?->allocated_amount),
            'version' => $this->version,
        ];
    }
}
