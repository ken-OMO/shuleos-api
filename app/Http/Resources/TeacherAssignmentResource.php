<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TeacherAssignmentResource extends JsonResource
{
    public function toArray(
        Request $request
    ): array
    {
        return [

            'id' => $this->id,

            'lessons_per_week'
                => $this->lessons_per_week,

            'is_class_teacher'
                => $this->is_class_teacher,

            'active'
                => $this->active,

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

            'lesson_plans' => $this->whenLoaded(

                'lessonPlans'

            ),

            'created_at'
                => $this->created_at,

        ];
    }
}
