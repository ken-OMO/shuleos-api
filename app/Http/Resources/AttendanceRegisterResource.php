<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AttendanceRegisterResource extends JsonResource
{
    public function toArray(Request $r): array
    {
        return ['id' => $this->id, 'attendance_date' => $this->attendance_date, 'register_type' => $this->register_type, 'lesson_period' => $this->lesson_period, 'status' => $this->status, 'grade_id' => $this->grade_id, 'stream_id' => $this->stream_id, 'session' => $this->whenLoaded('session'), 'opened_at' => $this->opened_at, 'finalized_at' => $this->finalized_at, 'records' => $this->whenLoaded('records')];
    }
}
