<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TimetableGenerationRunResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [

            'id' => $this->id,

            'school_id' => $this->school_id,

            'timetable_id' => $this->timetable_id,

            'generated_by' => $this->generated_by,

            'generation_type' => $this->generation_type,

            'status' => $this->status,

            'total_entries' => $this->total_entries,

            'total_conflicts' => $this->total_conflicts,

            'started_at' => $this->started_at,

            'completed_at' => $this->completed_at,

            'created_at' => $this->created_at,

        ];
    }
}
