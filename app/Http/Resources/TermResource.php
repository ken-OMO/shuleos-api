<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

use Illuminate\Http\Resources\Json\JsonResource;

class TermResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [

            'id' => $this->id,

            'school_id' => $this->school_id,

            'academic_year_id' => $this->academic_year_id,

            'term_name' => $this->term_name,

            'start_date' => $this->start_date,

            'end_date' => $this->end_date,

            'active' => $this->active,

            'school' => $this->whenLoaded(

                'school'

            ),

            'academic_year' => $this->whenLoaded(

                'academicYear'

            ),

            'academic_weeks' => $this->whenLoaded(

                'academicWeeks'

            ),

            'created_at' => $this->created_at,

        ];
    }
}
