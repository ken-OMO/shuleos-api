<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LessonPlanResource extends JsonResource
{
    public function toArray(
        Request $request
    ): array
    {
        return [

            'id' => $this->id,
            'school_id' => $this->school_id,
            'teacher_assignment_id' => $this->teacher_assignment_id,
            'scheme_lesson_id' => $this->scheme_lesson_id,

            'lesson_date' => $this->lesson_date,

            'introduction' => $this->introduction,

            'lesson_development'
                => $this->lesson_development,

            'conclusion'
                => $this->conclusion,

            'reflection'
                => $this->reflection,

            'status'
                => $this->status,

            'assignment' => $this->whenLoaded(

                'assignment'

            ),

            'scheme_lesson' => $this->whenLoaded(

                'schemeLesson'

            ),

            'notes' => $this->whenLoaded(

                'notes'

            ),

            'records_of_work' => $this->whenLoaded(

                'recordsOfWork'

            ),

            'created_by' => $this->created_by,

            'created_at' => $this->created_at,

        ];
    }
}
