<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LearningAreaAllocationResource extends JsonResource
{
    /**
     * Transform resource.
     */
    public function toArray(
        Request $request
    ): array {
        return [

            'id' => $this->id,

            'lessons_per_week' => $this->lessons_per_week,

            'active' => $this->active,

            'school' => $this->whenLoaded(

                'school'

            ),

            'grade' => $this->whenLoaded(

                'grade'

            ),

            'learning_area' => $this->whenLoaded(

                'learningArea'

            ),

            'created_at' => $this->created_at,

        ];
    }
}
