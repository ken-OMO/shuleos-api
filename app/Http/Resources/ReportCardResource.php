<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ReportCardResource extends JsonResource
{
    public function toArray(
        Request $request
    ): array
    {
        return [

            'id' => $this->id,

            'overall_score'
                => $this->overall_score,

            'overall_grade'
                => $this->overall_grade,

            'total_points'
                => $this->total_points,

            'stream_position'
                => $this->stream_position,

            'grade_position'
                => $this->grade_position,

            'school_position'
                => $this->school_position,

            'total_learners'
                => $this->total_learners,

            'attendance_percentage'
                => $this->attendance_percentage,

            'class_teacher_comment'
                => $this->class_teacher_comment,

            'principal_comment'
                => $this->principal_comment,

            'pathway_recommendation'
                => $this->pathway_recommendation,

            'school'
                => $this->whenLoaded(

                    'school'

                ),

            'learner'
                => $this->whenLoaded(

                    'learner'

                ),

            'exam'
                => $this->whenLoaded(

                    'exam'

                ),

            'academic_year'
                => $this->whenLoaded(

                    'academicYear'

                ),

            'term'
                => $this->whenLoaded(

                    'term'

                ),

            'generated_at'
                => $this->generated_at,

        ];
    }
}
