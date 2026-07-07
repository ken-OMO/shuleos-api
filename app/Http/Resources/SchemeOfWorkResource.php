<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SchemeOfWorkResource extends JsonResource
{
    public function toArray(
        Request $request
    ): array
    {
        return [

            'id' => $this->id,

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
