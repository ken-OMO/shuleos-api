<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TimetableConflictResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [

            'id' => $this->id,

            'timetable_id' => $this->timetable_id,

            'conflict_type' => $this->conflict_type,

            'severity' => $this->severity,

            'description' => $this->description,

            'resolved' => $this->resolved,

            'resolved_at' => $this->resolved_at,

            'created_at' => $this->created_at,

        ];
    }
}
