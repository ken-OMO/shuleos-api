<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AttendanceStatusResource extends JsonResource
{
    public function toArray(
        Request $request
    ): array
    {
        return [

            'id' => $this->id,

            'status_name'
                => $this->status_name,

            'status_code'
                => $this->status_code,

            'active'
                => $this->active,

            'attendance_records'
                => $this->whenLoaded(

                    'attendanceRecords'

                ),

            'created_at'
                => $this->created_at,

        ];
    }
}
