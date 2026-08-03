<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ReportCardLearningAreaResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'learning_area_id' => $this->learning_area_id, 'learning_area' => $this->whenLoaded('learningArea'), 'marks' => $this->marks_obtained, 'maximum_marks' => $this->maximum_marks, 'percentage' => $this->percentage, 'grade_code' => $this->grade_code, 'grade_description' => $this->gradingScale?->grade_description, 'points' => $this->points, 'teacher_comment' => $this->teacher_comment];
    }
}
