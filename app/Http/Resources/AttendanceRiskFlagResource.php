<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AttendanceRiskFlagResource extends JsonResource
{
    public function toArray(Request $r): array
    {
        return ['id' => $this->id, 'learner_id' => $this->learner_id, 'flag_type' => $this->flag_type, 'severity' => $this->severity, 'period_start' => $this->period_start, 'period_end' => $this->period_end, 'metric_value' => $this->metric_value, 'threshold_value' => $this->threshold_value, 'status' => $this->status, 'generated_at' => $this->generated_at];
    }
}
