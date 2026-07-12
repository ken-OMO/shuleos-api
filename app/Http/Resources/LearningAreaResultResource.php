<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LearningAreaResultResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'school_id' => $this->school_id,
            'exam_id' => $this->exam_id,
            'learner_id' => $this->learner_id,
            'learning_area_id' => $this->learning_area_id,
            'marks_obtained' => $this->marks_obtained,
            'maximum_marks' => $this->maximum_marks,
            'percentage' => $this->percentage,
            'grade_code' => $this->gradingScale?->grade_code,
            'grade_description' => $this->gradingScale?->grade_description,
            'points' => $this->gradingScale?->points,
            'processing_status' => $this->processing_status,
            'processed_by' => $this->processed_by,
            'processed_at' => $this->processed_at,
            'exam' => $this->whenLoaded('exam'),
            'learner' => $this->whenLoaded('learner'),
            'learning_area' => $this->whenLoaded('learningArea'),
            'grading_system' => $this->whenLoaded('gradingSystem'),
            'grading_scale' => $this->whenLoaded('gradingScale'),
            'processor' => $this->whenLoaded('processedBy'),
        ];
    }
}
