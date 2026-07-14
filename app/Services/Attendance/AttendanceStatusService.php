<?php

namespace App\Services\Attendance;

use App\Models\AttendanceStatus;
use Illuminate\Validation\ValidationException;

class AttendanceStatusService
{
    private const MAP = ['PRESENT' => 'attended', 'LATE' => 'late', 'ABSENT' => 'absent', 'SICK' => 'excused', 'EXCUSED' => 'excused', 'PERMISSION' => 'excused', 'ACTIVITY' => 'attended', 'SUSPENDED' => 'excluded'];

    public function status(string $idOrCode): AttendanceStatus
    {
        $s = AttendanceStatus::where('active', true)->where(fn ($q) => $q->where('id', $idOrCode)->orWhereRaw('UPPER(status_code)=?', [strtoupper($idOrCode)]))->first();
        if (! $s) {
            throw ValidationException::withMessages(['attendance_status' => 'Invalid active attendance status.']);
        }

        return $s;
    }

    public function category(AttendanceStatus|string $status): string
    {
        $code = $status instanceof AttendanceStatus ? $status->status_code : $status;

        return self::MAP[strtoupper($code)] ?? 'excluded';
    }

    public function attended(string $code): bool
    {
        return in_array($this->category($code), ['attended', 'late'], true);
    }

    public function denominator(string $code): bool
    {
        return $this->category($code) !== 'excluded';
    }
}
