<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TimetablePublicationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [

            'id' => $this->id,

            'school_id' => $this->school_id,

            'timetable_id' => $this->timetable_id,

            'publication_status' => $this->publication_status,

            'published_by' => $this->published_by,

            'published_at' => $this->published_at,

            'notes' => $this->notes,

            'created_at' => $this->created_at,

        ];
    }
}
