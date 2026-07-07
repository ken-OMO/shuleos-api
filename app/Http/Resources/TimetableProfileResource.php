<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TimetableProfileResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [

            'id' => $this->id,

            'school_id' => $this->school_id,

            'profile_name' => $this->profile_name,

            'education_level_id' => $this->education_level_id,

            'periods_per_day' => $this->periods_per_day,

            'periods_per_week' => $this->periods_per_week,

            'lesson_duration_minutes' => $this->lesson_duration_minutes,

            'allow_double_lessons' => $this->allow_double_lessons,

            'use_cbc_template' => $this->use_cbc_template,

            'active' => $this->active,

            'is_default' => $this->is_default,

            'created_at' => $this->created_at,

        ];
    }
}
