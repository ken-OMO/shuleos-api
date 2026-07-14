<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DisciplineCaseResource extends JsonResource
{
    public function toArray(Request $r): array
    {
        $privileged = in_array($r->user()?->role?->role_name, ['Teacher', 'HOD', 'Principal', 'Deputy Principal', 'School Admin'], true);

        return ['id' => $this->id, 'category' => $this->whenLoaded('category'), 'incident_date' => $this->incident_date, 'location' => $this->when(! $this->confidential || $privileged, $this->location), 'description' => $this->when(! $this->confidential || $privileged, $this->description), 'status' => $this->status, 'severity' => $this->severity, 'priority' => $this->priority, 'confidential' => (bool) $this->confidential, 'safeguarding' => $this->when($privileged, (bool) $this->safeguarding), 'actions' => $this->whenLoaded('actions'), 'created_at' => $this->created_at];
    }
}
