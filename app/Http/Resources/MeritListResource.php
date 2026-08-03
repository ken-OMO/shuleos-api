<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MeritListResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id, 'school_id' => $this->school_id, 'exam_id' => $this->exam_id,
            'learner_id' => $this->learner_id, 'grade_id' => $this->grade_id, 'stream_id' => $this->stream_id,
            'total_score' => $this->total_score, 'maximum_marks' => $this->maximum_marks,
            'average_percentage' => $this->average_percentage, 'total_points' => $this->total_points,
            'overall_grade' => $this->overallGradingScale?->grade_code,
            'grade_description' => $this->overallGradingScale?->grade_description,
            'overall_points' => $this->overallGradingScale?->points,
            'stream_position' => $this->stream_position, 'grade_position' => $this->grade_position,
            'school_position' => $this->school_position, 'ranking_method' => $this->ranking_method,
            'status' => $this->status, 'generated_by' => $this->generated_by,
            'generated_at' => $this->generated_at, 'published_at' => $this->published_at,
            'exam' => $this->whenLoaded('exam'), 'learner' => $this->whenLoaded('learner'),
            'grade' => $this->whenLoaded('grade'), 'stream' => $this->whenLoaded('stream'),
            'grading_system' => $this->whenLoaded('overallGradingSystem'),
            'grading_scale' => $this->whenLoaded('overallGradingScale'),
            'generator' => $this->whenLoaded('generatedBy'),
        ];
    }
}
