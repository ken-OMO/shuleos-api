<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

class ParentAttendanceResource extends ParentPortalArrayResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'date' => $this->attendance_date,
            'status' => $this->attendanceStatus?->status_name,
            'status_code' => $this->attendanceStatus?->status_code,
            'session' => $this->attendanceSession?->session_name,
            'late_minutes' => $this->late_minutes,
            'safe_reason' => $this->reason,
            'finalized' => (bool) $this->finalized,
        ];
    }
}
