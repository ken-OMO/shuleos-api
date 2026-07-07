<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ExamResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [

            'id' => $this->id,

            'exam_name' => $this->exam_name,

            'start_date' => $this->start_date,

            'end_date' => $this->end_date,

            'active' => $this->active,

            'assessment_type'
                => $this->whenLoaded('assessmentType'),

            'academic_year'
                => $this->whenLoaded('academicYear'),

            'term'
                => $this->whenLoaded('term'),

            'learning_areas'
                => $this->whenLoaded('learningAreas'),

            'created_at'
                => $this->created_at,

        ];
    }
}
