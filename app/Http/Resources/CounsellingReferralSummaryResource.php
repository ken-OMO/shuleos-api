<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CounsellingReferralSummaryResource extends JsonResource
{
    public function toArray(Request $r): array
    {
        return ['id' => $this->id, 'learner_id' => $this->learner_id, 'priority' => $this->priority, 'status' => $this->status, 'referred_at' => $this->referred_at, 'accepted_at' => $this->accepted_at, 'completed_at' => $this->completed_at];
    }
}
