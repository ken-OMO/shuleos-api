<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CurriculumCoverageResource extends JsonResource
{
    public function toArray(
        Request $request
    ): array
    {
        return [

            'id' => $this->id,
            'school_id' => $this->school_id,
            'teacher_assignment_id' => $this->teacher_assignment_id,
            'scheme_id' => $this->scheme_id,
            'scheme_lesson_id' => $this->scheme_lesson_id,
            'record_of_work_id' => $this->record_of_work_id,

            'date_completed'
                => $this->date_completed,

            'strand'
                => $this->strand,

            'sub_strand'
                => $this->sub_strand,

            'week_number'
                => $this->week_number,

            'completed'
                => $this->completed,

            'teacher_assignment'
                => $this->whenLoaded(

                    'teacherAssignment'

                ),

            'scheme'
                => $this->whenLoaded(

                    'scheme'

                ),

            'scheme_lesson'
                => $this->whenLoaded(

                    'schemeLesson'

                ),

            'record_of_work'
                => $this->whenLoaded(

                    'recordOfWork'

                ),

            'created_at'
                => $this->created_at,

        ];
    }
}
