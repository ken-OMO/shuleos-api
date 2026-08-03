<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class HomeworkAssignmentResource extends JsonResource
{
    public function toArray(Request $r): array
    {
        return ['id' => $this->id, 'title' => $this->title, 'instructions' => $this->instructions, 'assignment_type' => $this->assignment_type, 'submission_mode' => $this->submission_mode, 'total_marks' => $this->total_marks, 'grading_mode' => $this->grading_mode, 'publish_at' => $this->publish_at, 'published_at' => $this->published_at, 'due_at' => $this->due_at, 'allow_late_submission' => (bool) $this->allow_late_submission, 'maximum_attempts' => $this->maximum_attempts, 'allow_resubmission' => (bool) $this->allow_resubmission, 'status' => $this->status, 'learning_area_id' => $this->learning_area_id, 'grade_id' => $this->grade_id, 'stream_id' => $this->stream_id, 'resources' => $this->whenLoaded('resources'), 'rubric' => $this->whenLoaded('rubric'), 'created_at' => $this->created_at];
    }
}
