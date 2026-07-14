<?php

namespace App\Services\Attendance;

use App\Models\AttendanceRegister;
use App\Models\User;
use App\Services\LeadershipPortal\LeadershipPortalAccessService;
use Illuminate\Support\Facades\DB;

class AttendanceAnalyticsService
{
    public function __construct(private LeadershipPortalAccessService $access, private AttendanceStatusService $statuses) {}

    public function registers(User $u)
    {
        $scope = $this->access->scope($u);

        return AttendanceRegister::current()->where('school_id', $u->school_id)->when(! $scope['whole_school'], fn ($q) => $q->where('register_type', 'lesson')->whereHas('teacherAssignment', fn ($a) => $a->whereIn('learning_area_id', $scope['learning_area_ids'])));
    }

    public function summary(User $u): array
    {
        $registers = $this->registers($u);
        $records = DB::table('learner_attendance')->join('attendance_statuses', 'attendance_statuses.id', '=', 'learner_attendance.attendance_status_id')->where('learner_attendance.school_id', $u->school_id)->where('learner_attendance.finalized', true)->whereIn('attendance_register_id', (clone $registers)->select('id'));
        $counts = (clone $records)->selectRaw('UPPER(attendance_statuses.status_code) code,COUNT(*) total')->groupByRaw('UPPER(attendance_statuses.status_code)')->pluck('total', 'code');
        $possible = 0;
        $attended = 0;
        foreach ($counts as $code => $count) {
            if ($this->statuses->denominator($code)) {
                $possible += $count;
            }if ($this->statuses->attended($code)) {
                $attended += $count;
            }
        }

        return ['expected' => $possible, 'attended' => $attended, 'absent' => (int) ($counts['ABSENT'] ?? 0), 'late' => (int) ($counts['LATE'] ?? 0), 'excused' => (int) (($counts['SICK'] ?? 0) + ($counts['EXCUSED'] ?? 0) + ($counts['PERMISSION'] ?? 0)), 'attendance_rate' => $possible ? round($attended / $possible * 100, 2) : null, 'by_grade' => (clone $records)->selectRaw('learner_attendance.grade_id,COUNT(*) total')->groupBy('learner_attendance.grade_id')->get(), 'by_stream' => (clone $records)->selectRaw('learner_attendance.stream_id,COUNT(*) total')->groupBy('learner_attendance.stream_id')->get(), 'trend' => (clone $records)->selectRaw('learner_attendance.attendance_date,COUNT(*) total')->groupBy('learner_attendance.attendance_date')->orderBy('learner_attendance.attendance_date')->get(), 'missing_registers' => (clone $registers)->whereIn('status', ['draft', 'corrected'])->count()];
    }

    public function chronic(User $u)
    {
        $ids = $this->registers($u)->select('id');

        return DB::table('learner_attendance')->join('attendance_statuses', 'attendance_statuses.id', '=', 'learner_attendance.attendance_status_id')->where('learner_attendance.school_id', $u->school_id)->whereIn('attendance_register_id', $ids)->whereRaw('UPPER(attendance_statuses.status_code)=?', ['ABSENT'])->selectRaw('learner_id,COUNT(*) absence_count')->groupBy('learner_id')->havingRaw('COUNT(*) >= ?', [config('attendance.chronic_absence_count', 3)])->get();
    }
}
