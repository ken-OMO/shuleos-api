<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SchemeLessonResource extends JsonResource
{
    public function toArray(
        Request $request
    ): array
    {
        return [

            'id' => $this->id,

            'scheme_id' => $this->scheme_id,
            'week_id' => $this->week_id,

            'lesson_number' => $this->lesson_number,

            'strand' => $this->strand,

            'sub_strand' => $this->sub_strand,

            'specific_learning_outcome'
                => $this->specific_learning_outcome,

            'learning_experience'
                => $this->learning_experience,

            'resources'
                => $this->resources,

            'assessment_method'
                => $this->assessment_method,

            'week' => $this->whenLoaded(

                'week'

            ),

            'scheme' => $this->whenLoaded(

                'scheme'

            ),

            'lesson_plans' => $this->whenLoaded(

                'lessonPlans'

            ),

            'created_at' => $this->created_at,

        ];
    }
}
