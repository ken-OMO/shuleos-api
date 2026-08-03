<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RecurringCommunicationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'communication_id' => $this->communication_id, 'frequency' => $this->frequency, 'selected_weekdays' => is_string($this->selected_weekdays) ? json_decode($this->selected_weekdays, true) : $this->selected_weekdays, 'maximum_occurrences' => $this->maximum_occurrences, 'occurrences_dispatched' => $this->occurrences_dispatched, 'starts_at' => $this->starts_at, 'ends_at' => $this->ends_at, 'next_run_at' => $this->next_run_at, 'timezone' => $this->timezone, 'missed_run_policy' => $this->missed_run_policy, 'status' => $this->status];
    }
}
