<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TimetablePeriodResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [

            'id' => $this->id,

            'timetable_profile_id' => $this->timetable_profile_id,

            'period_name' => $this->period_name,

            'period_order' => $this->period_order,

            'start_time' => $this->start_time,

            'end_time' => $this->end_time,

            'is_teaching_period' => $this->is_teaching_period,

            'is_break' => $this->is_break,

            'is_lunch' => $this->is_lunch,

            'is_assembly' => $this->is_assembly,

            'is_games' => $this->is_games,

            'is_club' => $this->is_club,

            'active' => $this->active,

            'created_at' => $this->created_at,

        ];
    }
}
