<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RecordOfWorkResource extends JsonResource
{
    public function toArray(
        Request $request
    ): array {
        return [

            'id' => $this->id,
            'school_id' => $this->school_id,
            'lesson_plan_id' => $this->lesson_plan_id,

            'date_taught' => $this->date_taught,

            'content_covered' => $this->content_covered,

            'learner_response' => $this->learner_response,

            'teacher_reflection' => $this->teacher_reflection,

            'status' => $this->status,

            'lesson_plan' => $this->whenLoaded(

                'lessonPlan'

            ),

            'curriculum_coverage' => $this->whenLoaded(

                'curriculumCoverage'

            ),

            'created_by' => $this->created_by,

            'created_at' => $this->created_at,

        ];
    }
}
