<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AssessmentTypeResource extends JsonResource
{
    public function toArray(
        Request $request
    ): array {
        return [

            'id' => $this->id,
            'school_id' => $this->school_id,
            'exams_count' => $this->whenCounted('exams'),

            'assessment_type_name' => $this->assessment_type_name,

            'active' => $this->active,

            'school' => $this->whenLoaded(

                'school'

            ),

            'exams' => $this->whenLoaded(

                'exams'

            ),

            'created_at' => $this->created_at,

        ];
    }
}
