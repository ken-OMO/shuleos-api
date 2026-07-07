<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AssessmentRegistrationResource extends JsonResource
{
    public function toArray(
        Request $request
    ): array
    {
        return [

            'id' => $this->id,

            'assessment_type'
                => $this->assessment_type,

            'assessment_year'
                => $this->assessment_year,

            'candidate_number'
                => $this->candidate_number,

            'registration_number'
                => $this->registration_number,

            'status'
                => $this->status,

            'school'
                => $this->whenLoaded(

                    'school'

                ),

            'learner'
                => $this->whenLoaded(

                    'learner'

                ),

            'creator'
                => $this->whenLoaded(

                    'creator'

                ),

            'created_at'
                => $this->created_at,

        ];
    }
}
