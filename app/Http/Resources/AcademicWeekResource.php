<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AcademicWeekResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [

            'id' => $this->id,

            'school_id' => $this->school_id,

            'academic_year_id' => $this->academic_year_id,

            'term_id' => $this->term_id,

            'week_number' => $this->week_number,

            'start_date' => $this->start_date,

            'end_date' => $this->end_date,

            'active' => $this->active,

            'school' => $this->whenLoaded(

                'school'

            ),

            'academic_year' => $this->whenLoaded(

                'academicYear'

            ),

            'term' => $this->whenLoaded(

                'term'

            ),

            'created_at' => $this->created_at,

        ];
    }
}
