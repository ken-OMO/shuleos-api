<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AcademicYearResource extends JsonResource
{
    /**
     * Transform resource.
     */
    public function toArray(
        Request $request
    ): array
    {
        return [

            'id' => $this->id,

            'year_name' => $this->year_name,

            'start_date' => $this->start_date,

            'end_date' => $this->end_date,

            'active' => $this->active,

            'school' => $this->whenLoaded(

                'school'

            ),

            'terms' => $this->whenLoaded(

                'terms'

            ),

            'academic_weeks' => $this->whenLoaded(

                'academicWeeks'

            ),

            'created_at' => $this->created_at,

        ];
    }
}
