<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TeacherAssignmentResource extends JsonResource
{
    public function toArray(
        Request $request
    ): array {
        return [

            'id' => $this->id,

            'school_id' => $this->school_id,

            'teacher_id' => $this->teacher_id,

            'learning_area_id' => $this->learning_area_id,

            'grade_id' => $this->grade_id,

            'stream_id' => $this->stream_id,

            'academic_year_id' => $this->academic_year_id,

            'term_id' => $this->term_id,

            'lessons_per_week' => $this->lessons_per_week,

            'is_class_teacher' => $this->is_class_teacher,

            'active' => $this->active,

            'teacher' => $this->whenLoaded(

                'teacher'

            ),

            'learning_area' => $this->whenLoaded(

                'learningArea'

            ),

            'grade' => $this->whenLoaded(

                'grade'

            ),

            'stream' => $this->whenLoaded(

                'stream'

            ),

            'academic_year' => $this->whenLoaded(

                'academicYear'

            ),

            'term' => $this->whenLoaded(

                'term'

            ),

            'created_at' => $this->created_at,

        ];
    }
}
