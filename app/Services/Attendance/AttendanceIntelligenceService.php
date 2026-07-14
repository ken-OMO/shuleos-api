<?php

namespace App\Services\Attendance;

use App\Models\AttendanceRiskFlag;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AttendanceIntelligenceService
{
    public function generate(?string $school = null): int
    {
        $schools = $school ? collect([$school]) : DB::table('schools')->pluck('id');
        $made = 0;
        foreach ($schools as $s) {
            $learners = DB::table('learner_attendance')->join('attendance_registers', 'attendance_registers.id', '=', 'learner_attendance.attendance_register_id')->where('learner_attendance.school_id', $s)->where('learner_attendance.finalized', true)->where('attendance_registers.status', 'finalized')->distinct()->pluck('learner_attendance.learner_id');
            foreach ($learners as $learner) {
                $from = now()->subDays(30)->toDateString();
                $to = now()->toDateString();
                $rows = DB::table('learner_attendance')->join('attendance_statuses', 'attendance_statuses.id', '=', 'learner_attendance.attendance_status_id')->where('learner_attendance.school_id', $s)->where('learner_id', $learner)->where('finalized', true)->whereBetween('attendance_date', [$from, $to])->select('attendance_statuses.status_code')->get();
                $total = $rows->count();
                $absent = $rows->filter(fn ($r) => strtoupper($r->status_code) === 'ABSENT')->count();
                $late = $rows->filter(fn ($r) => strtoupper($r->status_code) === 'LATE')->count();
                $rules = [
                    ['repeated_absence', $absent, config('attendance.risk.repeated_absence', 3), false],
                    ['repeated_lateness', $late, config('attendance.risk.repeated_lateness', 3), false],
                    ['low_attendance_rate', $total ? round(($total - $absent) / $total * 100, 2) : 100, config('attendance.risk.low_rate', 80), true],
                ];
                foreach ($rules as [$type, $metric, $threshold, $inverse]) {
                    $trigger = $inverse ? $metric < $threshold : $metric >= $threshold;
                    $identity = ['school_id' => $s, 'learner_id' => $learner, 'flag_type' => $type, 'period_start' => $from, 'period_end' => $to];
                    if ($trigger) {
                        $flag = AttendanceRiskFlag::firstOrNew($identity);
                        if (! $flag->exists) {
                            $flag->id = (string) Str::uuid();
                            $flag->status = 'open';
                        }
                        $flag->fill(['severity' => $type === 'low_attendance_rate' && $metric < 60 ? 'critical' : ($metric >= ($threshold * 2) ? 'high' : 'medium'), 'metric_value' => $metric, 'threshold_value' => $threshold, 'generated_at' => now(), 'metadata' => ['finalized_records' => $total]])->save();
                        $made++;
                    } else {
                        AttendanceRiskFlag::where($identity)->whereIn('status', ['open', 'acknowledged'])->update(['status' => 'resolved', 'resolved_at' => now(), 'resolution_notes' => 'Automatically resolved after finalized attendance recalculation.']);
                    }
                }
            }
        }

        return $made;
    }

    public function update(User $u, string $id, string $to, string $notes): AttendanceRiskFlag
    {
        $f = AttendanceRiskFlag::whereKey($id)->where('school_id', $u->school_id)->where('status', 'open')->firstOrFail();
        $f->update($to === 'acknowledged' ? ['status' => 'acknowledged', 'acknowledged_by' => $u->id, 'acknowledged_at' => now(), 'resolution_notes' => $notes] : ['status' => 'resolved', 'resolved_by' => $u->id, 'resolved_at' => now(), 'resolution_notes' => $notes]);

        return $f;
    }
}
