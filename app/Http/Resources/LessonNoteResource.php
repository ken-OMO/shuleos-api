<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LessonNoteResource extends JsonResource
{
    public function toArray(
        Request $request
    ): array
    {
        return [

            'id' => $this->id,
            'school_id' => $this->school_id,
            'lesson_plan_id' => $this->lesson_plan_id,

            'note_content'
                => $this->note_content,

            'lesson_plan' => $this->whenLoaded(

                'lessonPlan'

            ),

            'created_by'
                => $this->created_by,

            'created_at'
                => $this->created_at,

        ];
    }
}
