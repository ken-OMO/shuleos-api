<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TimetableResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [

            'id' => $this->id,

            'timetable_profile_id' => $this->timetable_profile_id,

            'academic_year_id' => $this->academic_year_id,

            'term_id' => $this->term_id,

            'timetable_name' => $this->timetable_name,

            'status' => $this->status,

            'active' => $this->active,

            'created_at' => $this->created_at,

            'validated_at' => $this->validated_at,

            'published_at' => $this->published_at,

            'entries' => TimetableEntryResource::collection($this->whenLoaded('entries')),

        ];
    }
}
