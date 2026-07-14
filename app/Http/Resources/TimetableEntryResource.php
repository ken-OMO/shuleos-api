<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TimetableEntryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [

            'id' => $this->id,

            'timetable_id' => $this->timetable_id,

            'day_of_week' => $this->day_of_week,

            'period_id' => $this->period_id,

            'grade_id' => $this->grade_id,

            'stream_id' => $this->stream_id,

            'learning_area_id' => $this->learning_area_id,

            'teacher_id' => $this->teacher_id,

            'room_id' => $this->room_id,

            'remarks' => $this->remarks,

            'entry_status' => $this->entry_status,

            'is_double_lesson' => (bool) $this->is_double_lesson,

            'lesson_group_id' => $this->lesson_group_id,

            'lesson_sequence' => $this->lesson_sequence,

            'lesson_span' => $this->lesson_span,

            'period' => $this->whenLoaded('period'),

            'room' => $this->whenLoaded('room'),

            'created_at' => $this->created_at,

        ];
    }
}
