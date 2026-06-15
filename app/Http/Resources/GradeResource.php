<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class GradeResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        return [

            'id' => $this->id,

            'grade_name' => $this->grade_name,

            'grade_order' => $this->grade_order,

            'active' => $this->active,

            'education_level' => $this->whenLoaded(
                'educationLevel'
            ),

            'school' => $this->whenLoaded(
                'school'
            ),

            'created_at' => $this->created_at,

        ];
    }
}
