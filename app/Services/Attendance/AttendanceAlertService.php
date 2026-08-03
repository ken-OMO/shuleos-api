<?php

namespace App\Services\Attendance;

use App\Models\AttendanceAlert;
use App\Models\AttendanceRegister;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AttendanceAlertService
{
    public function process(AttendanceRegister $register): int
    {
        $count = 0;
        $rows = $register->records()->with('attendanceStatus')->get();
        foreach ($rows as $row) {
            $code = strtoupper((string) $row->attendanceStatus?->status_code);
            if ($code !== 'ABSENT' && ! ($code === 'LATE' && $row->is_late_minutes >= config('attendance.late_alert_minutes', 30))) {
                continue;
            }$alert = AttendanceAlert::firstOrCreate(['school_id' => $register->school_id, 'attendance_id' => $row->id], ['id' => (string) Str::uuid(), 'learner_id' => $row->learner_id, 'parent_notified' => false, 'notification_method' => 'portal', 'created_at' => now()]);
            if (! $alert->parent_notified) {
                $users = DB::table('learner_parents')->join('parents', 'parents.id', '=', 'learner_parents.parent_id')->join('users', 'users.id', '=', 'parents.user_id')->where('learner_parents.learner_id', $row->learner_id)->where('learner_parents.active', true)->where('learner_parents.portal_enabled', true)->where('learner_parents.is_deleted', false)->where('parents.school_id', $register->school_id)->where('parents.active', true)->where('parents.is_deleted', false)->where('users.active', true)->pluck('users.id');
                foreach ($users as $user) {
                    DB::table('notifications')->insert(['id' => (string) Str::uuid(), 'school_id' => $register->school_id, 'user_id' => $user, 'title' => 'Attendance update', 'message' => $code === 'ABSENT' ? 'Your linked learner was marked absent.' : 'Your linked learner was marked late.', 'is_read' => false, 'created_at' => now()]);
                }if ($users->isNotEmpty()) {
                    $alert->update(['parent_notified' => true, 'notified_at' => now()]);
                }
            }$count++;
        }

        return $count;
    }
}
