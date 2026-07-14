<?php

namespace App\Services\Attendance;

use App\Models\AttendanceRegister;
use App\Models\LearnerAttendance;
use App\Models\TeacherAssignment;
use App\Models\User;
use App\Services\LeadershipPortal\LeadershipPortalAccessService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AttendanceService
{
    public function __construct(private AttendanceStatusService $statuses, private AttendanceAlertService $alerts) {}

    public function teacher(User $u)
    {
        return DB::table('teachers')->where('user_id', $u->id)->where('school_id', $u->school_id)->where('active', true)->where('is_deleted', false)->first() ?: throw new AuthorizationException('Active teacher profile required.');
    }

    public function ownQuery(User $u)
    {
        $t = $this->teacher($u);

        return AttendanceRegister::current()->where('school_id', $u->school_id)->where('teacher_id', $t->id);
    }

    public function open(User $u, array $data): AttendanceRegister
    {
        return DB::transaction(function () use ($u, $data) {
            $teacher = $this->teacher($u);
            $a = TeacherAssignment::current()->whereKey($data['teacher_assignment_id'])->where('school_id', $u->school_id)->where('teacher_id', $teacher->id)->where('active', true)->firstOrFail();
            abort_if(! $a->stream_id, 422, 'A stream-scoped teacher assignment is required.');
            if ($data['register_type'] === 'daily' && ! $a->is_class_teacher) {
                throw new AuthorizationException('Daily attendance requires an active class-teacher assignment.');
            }abort_unless(DB::table('attendance_sessions')->where('id', $data['attendance_session_id'])->where('school_id', $u->school_id)->where('active', true)->exists(), 422);
            $existing = AttendanceRegister::where('school_id', $u->school_id)->where('attendance_date', $data['attendance_date'])->where('stream_id', $a->stream_id)->where('attendance_session_id', $data['attendance_session_id'])->where('lesson_period', $data['lesson_period'] ?? null)->where('is_deleted', false)->lockForUpdate()->first();
            if ($existing) {
                return $existing;
            }$r = AttendanceRegister::create(['id' => (string) Str::uuid(), 'school_id' => $u->school_id, 'attendance_session_id' => $data['attendance_session_id'], 'teacher_assignment_id' => $a->id, 'teacher_id' => $teacher->id, 'grade_id' => $a->grade_id, 'stream_id' => $a->stream_id, 'academic_year_id' => $a->academic_year_id, 'term_id' => $a->term_id, 'attendance_date' => $data['attendance_date'], 'lesson_period' => $data['lesson_period'] ?? null, 'register_type' => $data['register_type'], 'status' => 'draft', 'opened_by' => $u->id, 'opened_at' => now()]);
            $learners = $this->eligibleLearners($r)->get();
            $this->audit($r, $u, 'register_opened', metadata: ['learner_count' => $learners->count()]);

            return $r->load('records.learner', 'records.attendanceStatus', 'session');
        });
    }

    public function save(User $u, string $id, array $marks, ?string $reason = null): AttendanceRegister
    {
        return DB::transaction(function () use ($u, $id, $marks, $reason) {
            $r = $this->ownQuery($u)->whereKey($id)->lockForUpdate()->firstOrFail();
            abort_unless(in_array($r->status, ['draft', 'corrected'], true), 409);
            foreach ($marks as $mark) {
                abort_unless($this->eligibleLearners($r)->where('id', $mark['learner_id'])->exists(), 422, 'Learner is outside the register roster.');
                $row = $r->records()->where('learner_id', $mark['learner_id'])->lockForUpdate()->first();
                $status = $this->statuses->status($mark['attendance_status_id'] ?? $mark['status_code']);
                if (strtoupper($status->status_code) === 'LATE' && empty($mark['late_minutes'])) {
                    throw ValidationException::withMessages(['late_minutes' => 'Late minutes are required for LATE status.']);
                }$old = $row?->attendance_status_id;
                $oldRemarks = $row?->remarks;
                $values = ['attendance_status_id' => $status->id, 'remarks' => $mark['remarks'] ?? null, 'is_late_minutes' => strtoupper($status->status_code) === 'LATE' ? $mark['late_minutes'] : null, 'marked_at' => now(), 'updated_by' => $u->id, 'source' => $reason ? 'admin_correction' : 'teacher', 'correction_reason' => $reason];
                if ($row) {
                    $row->update($values);
                } else {
                    $row = LearnerAttendance::create($values + ['id' => (string) Str::uuid(), 'school_id' => $u->school_id, 'learner_id' => $mark['learner_id'], 'grade_id' => $r->grade_id, 'stream_id' => $r->stream_id, 'attendance_register_id' => $r->id, 'attendance_session_id' => $r->attendance_session_id, 'attendance_date' => $r->attendance_date, 'marked_by' => $u->id, 'finalized' => false]);
                }
                $this->audit($r, $u, $reason ? 'learner_corrected' : 'draft_mark_saved', $row, $old, $status->id, $oldRemarks, $row->remarks, $reason);
            }

            return $r->fresh('records.attendanceStatus');
        });
    }

    public function finalize(User $u, string $id): AttendanceRegister
    {
        return DB::transaction(function () use ($u, $id) {
            $r = $this->ownQuery($u)->whereKey($id)->lockForUpdate()->firstOrFail();
            abort_unless($r->status === 'draft', 409);
            if ($r->records()->count() !== $this->eligibleLearners($r)->count()) {
                throw ValidationException::withMessages(['register' => 'Every allocated learner must be marked before finalization.']);
            }$r->records()->update(['finalized' => true]);
            $r->update(['status' => 'finalized', 'finalized_by' => $u->id, 'finalized_at' => now()]);
            $this->audit($r, $u, 'register_finalized');
            $this->alerts->process($r);

            return $r->fresh('records.attendanceStatus');
        });
    }

    public function reopen(User $u, string $id, string $reason): AttendanceRegister
    {
        return DB::transaction(function () use ($u, $id, $reason) {
            $r = $this->ownQuery($u)->whereKey($id)->where('status', 'finalized')->lockForUpdate()->firstOrFail();
            abort_if($r->finalized_at->lt(now()->subHours(config('attendance.teacher_correction_hours', 24))), 403, 'Teacher correction window has closed.');
            $r->records()->update(['finalized' => false]);
            $r->update(['status' => 'corrected', 'correction_reason' => $reason, 'corrected_by' => $u->id, 'corrected_at' => now()]);
            $this->audit($r, $u, 'register_reopened', reason: $reason);

            return $r;
        });
    }

    public function cancel(User $u, string $id): void
    {
        DB::transaction(function () use ($u, $id) {
            $r = $this->ownQuery($u)->whereKey($id)->where('status', 'draft')->lockForUpdate()->firstOrFail();
            abort_if($r->records()->whereNotNull('attendance_status_id')->exists(), 409, 'Marked draft register cannot be cancelled.');
            $r->update(['status' => 'cancelled']);
            $this->audit($r, $u, 'register_cancelled');
        });
    }

    public function leadershipCorrect(User $u, string $id, array $marks, string $reason): AttendanceRegister
    {
        $scope = app(LeadershipPortalAccessService::class)->scope($u);
        if (! $scope['whole_school']) {
            throw new AuthorizationException('Attendance correction requires whole-school leadership scope.');
        }

        return DB::transaction(function () use ($u, $id, $marks, $reason) {
            $register = AttendanceRegister::current()
                ->where('school_id', $u->school_id)
                ->whereKey($id)
                ->whereIn('status', ['finalized', 'corrected'])
                ->lockForUpdate()
                ->firstOrFail();

            foreach ($marks as $mark) {
                abort_unless($this->eligibleLearners($register)->where('id', $mark['learner_id'])->exists(), 422, 'Learner is outside the register roster.');
                $row = $register->records()->where('learner_id', $mark['learner_id'])->lockForUpdate()->firstOrFail();
                $status = $this->statuses->status($mark['attendance_status_id'] ?? $mark['status_code']);
                if (strtoupper($status->status_code) === 'LATE' && empty($mark['late_minutes'])) {
                    throw ValidationException::withMessages(['late_minutes' => 'Late minutes are required for LATE status.']);
                }

                $oldStatus = $row->attendance_status_id;
                $oldRemarks = $row->remarks;
                $row->update([
                    'attendance_status_id' => $status->id,
                    'remarks' => $mark['remarks'] ?? null,
                    'is_late_minutes' => strtoupper($status->status_code) === 'LATE' ? $mark['late_minutes'] : null,
                    'marked_at' => now(),
                    'updated_by' => $u->id,
                    'source' => 'admin_correction',
                    'correction_reason' => $reason,
                    'finalized' => true,
                ]);
                $this->audit($register, $u, 'leadership_correction', $row, $oldStatus, $status->id, $oldRemarks, $row->remarks, $reason);
            }

            $register->update(['status' => 'corrected', 'correction_reason' => $reason, 'corrected_by' => $u->id, 'corrected_at' => now()]);
            $this->alerts->process($register);

            return $register->fresh('records.attendanceStatus');
        });
    }

    public function audit(AttendanceRegister $r, User $u, string $action, ?LearnerAttendance $row = null, ?string $old = null, ?string $new = null, ?string $oldRemarks = null, ?string $newRemarks = null, ?string $reason = null, array $metadata = []): void
    {
        DB::table('attendance_audit_logs')->insert(['id' => (string) Str::uuid(), 'school_id' => $r->school_id, 'attendance_register_id' => $r->id, 'learner_attendance_id' => $row?->id, 'actor_user_id' => $u->id, 'action' => $action, 'previous_status_id' => $old, 'new_status_id' => $new, 'previous_remarks' => $oldRemarks, 'new_remarks' => $newRemarks, 'reason' => $reason, 'metadata' => $metadata ? json_encode($metadata) : null, 'created_at' => now()]);
    }

    public function eligibleLearners(AttendanceRegister $register)
    {
        return DB::table('learners')->where('school_id', $register->school_id)->where('grade_id', $register->grade_id)->where('stream_id', $register->stream_id)->where('active', true)->where('is_deleted', false);
    }
}
