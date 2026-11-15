<?php

declare(strict_types=1);

namespace App\Services\StudentElection;

use App\Models\StudentElection;
use App\Models\User;
use App\Services\Auth\AuthContextService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StudentElectionClassroomAccess
{
    public function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }

    public function enabled(): void
    {
        abort_unless(config('student_election_stations.enabled') === true && ! config('telescope.enabled'), 503, 'Classroom elections are not enabled.');
        abort_if(! app()->environment('testing') && in_array(config('cache.default'), ['array', 'null'], true), 503, 'A persistent limiter store is required.');
    }

    public function actor(User $user, string $permission): User
    {
        $this->enabled();
        $user = User::withoutGlobalScopes()->where('school_id', $user->school_id)->findOrFail($user->id);
        app(AuthContextService::class)->assertAccessible($user);
        abort_unless($user->getAttribute('school_id') !== null && app(AuthContextService::class)->hasPermission($user, $permission), 403);
        $school = DB::table('schools')->where('id', $user->school_id)->firstOrFail();
        abort_if(in_array($school->lifecycle_state, ['read_only', 'suspended', 'locked', 'archived'], true), 403);

        return $user;
    }

    public function timezone(StudentElection $e): string
    {
        $tz = $e->ballot_timezone ?: (DB::table('schools')->where('id', $e->school_id)->value('timezone') ?: config('app.timezone'));
        try {
            new \DateTimeZone($tz);
        } catch (\Exception) {
            $this->fail('school', 'School timezone is invalid.');
        }

        return $tz;
    }

    public function assignment(StudentElection $e, string $id, bool $lock = false): object
    {
        $today = now($this->timezone($e))->toDateString();
        $a = DB::table('teacher_assignments')->where('id', $id)->where('school_id', $e->school_id)
            ->where('academic_year_id', $e->academic_year_id)->where('is_class_teacher', true)
            ->where('active', true)->where('is_deleted', false);
        $a = ($lock ? $a->sharedLock() : $a)->first();
        if (! $a || ! $a->stream_id) {
            $this->fail('assignment', 'An active class-teacher assignment is required.');
        }
        $term = DB::table('terms')->where('id', $a->term_id)->where('school_id', $e->school_id)
            ->where('academic_year_id', $e->academic_year_id)->where('active', true)
            ->whereDate('start_date', '<=', $today)->whereDate('end_date', '>=', $today)->first();
        $teacher = DB::table('teachers')->where('id', $a->teacher_id)->where('school_id', $e->school_id)
            ->where('active', true)->where('is_deleted', false)->first();
        if (! $term || ! $teacher || ! DB::table('academic_years')->where('id', $e->academic_year_id)->where('school_id', $e->school_id)->where('active', true)->exists()
            || ! DB::table('grades')->where('id', $a->grade_id)->where('school_id', $e->school_id)->where('active', true)->exists()
            || ! DB::table('streams')->where('id', $a->stream_id)->where('school_id', $e->school_id)->where('grade_id', $a->grade_id)->where('active', true)->exists()
            || ($e->status === 'voting_open' && $e->term_id !== null && $e->term_id !== $a->term_id)) {
            $this->fail('assignment', 'Class responsibility or its dated academic scope is unavailable.');
        }
        $a->user_id = $teacher->user_id;

        return $a;
    }

    public function teacher(User $user, StudentElection $e, string $assignment, bool $lock = false): object
    {
        $user = $this->actor($user, 'operate_student_election_stations');
        abort_unless((string) $user->school_id === (string) $e->school_id, 403);
        $a = $this->assignment($e, $assignment, $lock);
        abort_unless($a->user_id === $user->id, 403);

        return $a;
    }

    public function learner(StudentElection $e, object $a, string $id): object
    {
        $l = DB::table('learners')->where('id', $id)->where('school_id', $e->school_id)->where('grade_id', $a->grade_id)
            ->where('stream_id', $a->stream_id)->where('active', true)->where('portal_enabled', true)->where('is_deleted', false)->first();
        if (! $l || ! $l->user_id) {
            $this->fail('learner', 'Learner is not available in this classroom.');
        }
        $user = User::withoutGlobalScopes()->where('school_id', $e->school_id)->findOrFail($l->user_id);
        app(AuthContextService::class)->assertAccessible($user);
        if (app(StudentElectionLearnerAccess::class)->learner($user)->id !== $l->id) {
            $this->fail('learner', 'Learner identity is inconsistent.');
        }

        return $l;
    }

    public function attendance(StudentElection $e, object $a, object $learner, string $attendanceId): void
    {
        $row = DB::table('learner_attendance')->where('id', $attendanceId)->first();
        if (! $row || ! $row->attendance_register_id) {
            $this->fail('attendance', 'Finalized daily attendance is required.');
        }
        // Existing attendance writes lock the register before its records.
        $reg = DB::table('attendance_registers')->where('id', $row->attendance_register_id)->sharedLock()->firstOrFail();
        $row = DB::table('learner_attendance')->where('id', $attendanceId)->sharedLock()->firstOrFail();
        $status = DB::table('attendance_statuses')->where('id', $row->attendance_status_id)->where('active', true)->value('status_code');
        $today = now($this->timezone($e))->toDateString();
        if ($row->learner_id !== $learner->id || $row->school_id !== $e->school_id || $reg->school_id !== $e->school_id
            || $row->attendance_register_id !== $reg->id || ! $row->finalized || $reg->is_deleted
            || ! in_array($reg->status, ['finalized', 'corrected'], true) || $reg->register_type !== 'daily'
            || $row->attendance_date !== $today || $reg->attendance_date !== $today
            || $row->grade_id !== $a->grade_id || $row->stream_id !== $a->stream_id
            || $reg->grade_id !== $a->grade_id || $reg->stream_id !== $a->stream_id
            || ! in_array(strtoupper((string) $status), ['PRESENT', 'LATE'], true)) {
            $this->fail('attendance', 'Current finalized daily presence in this classroom is required.');
        }
    }
}
