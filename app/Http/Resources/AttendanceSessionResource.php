<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AttendanceSessionResource extends JsonResource
{
    public function toArray(
        Request $request
    ): array {
        return [

            'id' => $this->id,

            'session_name' => $this->session_name,

            'session_order' => $this->session_order,

            'active' => $this->active,

            'school' => $this->whenLoaded(

                'school'

            ),

            'attendance_records' => $this->whenLoaded(

                'attendanceRecords'

            ),

            'created_at' => $this->created_at,

        ];
    }
}
