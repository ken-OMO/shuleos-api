<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ReportCardResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'school_id' => $this->school_id, 'learner_id' => $this->learner_id, 'exam_id' => $this->exam_id, 'academic_year_id' => $this->academic_year_id, 'term_id' => $this->term_id, 'grade_id' => $this->grade_id, 'stream_id' => $this->stream_id, 'learner' => $this->whenLoaded('learner'), 'exam' => $this->whenLoaded('exam'), 'academic_year' => $this->whenLoaded('academicYear'), 'term' => $this->whenLoaded('term'), 'grade' => $this->whenLoaded('grade'), 'stream' => $this->whenLoaded('stream'), 'overall_score' => $this->overall_score, 'maximum_marks' => $this->maximum_marks, 'average_percentage' => $this->average_percentage, 'overall_grade' => $this->overallGradingScale?->grade_code, 'grade_description' => $this->overallGradingScale?->grade_description, 'overall_points' => $this->overallGradingScale?->points, 'total_points' => $this->total_points, 'stream_position' => $this->stream_position, 'grade_position' => $this->grade_position, 'school_position' => $this->school_position, 'total_learners' => $this->total_learners, 'attendance' => ['present' => $this->attendance_present, 'absent' => $this->attendance_absent, 'late' => $this->attendance_late, 'total_sessions' => $this->attendance_total_sessions, 'percentage' => $this->attendance_percentage], 'class_teacher_comment' => $this->class_teacher_comment, 'principal_comment' => $this->principal_comment, 'pathway_recommendation_id' => $this->pathway_recommendation_id, 'pathway_recommendation' => $this->pathway_recommendation, 'pathway' => $this->whenLoaded('pathwayRecommendation'), 'status' => $this->status, 'generated_by' => $this->generated_by, 'generated_at' => $this->generated_at, 'published_by' => $this->published_by, 'published_at' => $this->published_at, 'learning_areas' => ReportCardLearningAreaResource::collection($this->whenLoaded('learningAreas'))];
    }
}
