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
            'school_id' => $this->school_id,
            'assessment_type_id' => $this->assessment_type_id,
            'academic_year_id' => $this->academic_year_id,
            'term_id' => $this->term_id,
            'status' => $this->status,

            'exam_name' => $this->exam_name,

            'start_date' => $this->start_date,

            'end_date' => $this->end_date,

            'active' => $this->active,

            'assessment_type' => $this->whenLoaded('assessmentType'),

            'academic_year' => $this->whenLoaded('academicYear'),

            'term' => $this->whenLoaded('term'),

            'learning_areas' => $this->whenLoaded('learningAreas'),

            'created_at' => $this->created_at,

        ];
    }
}
