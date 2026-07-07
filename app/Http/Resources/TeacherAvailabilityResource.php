<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TeacherAvailabilityResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [

            'id' => $this->id,

            'school_id' => $this->school_id,

            'teacher_id' => $this->teacher_id,

            'day_of_week' => $this->day_of_week,

            'period_id' => $this->period_id,

            'is_available' => $this->is_available,

            'remarks' => $this->remarks,

            'created_at' => $this->created_at,

        ];
    }
}
