<?php

namespace App\Http\Resources;

use App\Models\ReportCardLearningArea;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ReportCardLearningArea
 */
class ReportCardLearningAreaResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'learning_area_id' => $this->learning_area_id, 'learning_area' => $this->whenLoaded('learningArea'), 'is_absent' => (bool) $this->is_absent, 'marks' => $this->marks_obtained, 'maximum_marks' => $this->maximum_marks, 'percentage' => $this->percentage, 'grading_snapshot' => $this->grading_snapshot, 'grade_code' => ($this->grading_snapshot !== null ? $this->grading_snapshot['grade_code'] : $this->grade_code), 'grade_description' => ($this->grading_snapshot !== null ? $this->grading_snapshot['grade_description'] : $this->gradingScale?->grade_description), 'points' => ($this->grading_snapshot !== null ? $this->grading_snapshot['points'] : $this->points), 'teacher_comment' => $this->teacher_comment];
    }
}
