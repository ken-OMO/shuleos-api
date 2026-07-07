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
