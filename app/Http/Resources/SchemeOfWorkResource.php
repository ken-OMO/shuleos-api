<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SchemeOfWorkResource extends JsonResource
{
    public function toArray(
        Request $request
    ): array {
        return [

            'id' => $this->id,

            'school_id' => $this->school_id,
            'learning_area_id' => $this->learning_area_id,
            'grade_id' => $this->grade_id,
            'academic_year_id' => $this->academic_year_id,
            'term_id' => $this->term_id,

            'title' => $this->title,

            'active' => $this->active,

            'school' => $this->whenLoaded(

                'school'

            ),

            'learning_area' => $this->whenLoaded(

                'learningArea'

            ),

            'grade' => $this->whenLoaded(

                'grade'

            ),

            'academic_year' => $this->whenLoaded(

                'academicYear'

            ),

            'term' => $this->whenLoaded(

                'term'

            ),

            'lessons' => $this->whenLoaded(

                'lessons'

            ),

            'created_by' => $this->created_by,

            'created_at' => $this->created_at,

        ];
    }
}
