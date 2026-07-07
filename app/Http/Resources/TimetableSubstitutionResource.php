<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TimetableSubstitutionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [

            'id' => $this->id,

            'school_id' => $this->school_id,

            'timetable_entry_id' => $this->timetable_entry_id,

            'absent_teacher_id' => $this->absent_teacher_id,

            'substitute_teacher_id' => $this->substitute_teacher_id,

            'substitution_date' => $this->substitution_date,

            'reason' => $this->reason,

            'approved_by' => $this->approved_by,

            'created_at' => $this->created_at,

        ];
    }
}
