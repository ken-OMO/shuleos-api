<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FinanceClearanceCertificateResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['certificate_number' => $this->certificate_number, 'learner_id' => $this->learner_id, 'issued_at' => $this->issued_at, 'expires_at' => $this->expires_at, 'revoked_at' => $this->revoked_at];
    }
}
