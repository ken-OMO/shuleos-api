<?php

namespace App\Services\TeacherPortal;

use App\Models\Learner;
use App\Models\Teacher;
use App\Models\TeacherAssignment;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class TeacherPortalAccessService
{
    public function teacher(User $u): Teacher
    {
        if (! $u->id || ! $u->school_id || ! $u->active || (Schema::hasColumn('users', 'is_deleted') && $u->is_deleted)) {
            throw new AuthorizationException('Active tenant membership required.');
        }
        $school = DB::table('schools')->where('id', $u->school_id)
            ->when(Schema::hasColumn('schools', 'active'), fn ($query) => $query->where('active', true))
            ->when(Schema::hasColumn('schools', 'is_deleted'), fn ($query) => $query->where('is_deleted', false))->exists();
        if (! $school) {
            throw new AuthorizationException('Active school membership required.');
        }
        $role = strtolower((string) $u->role?->role_name);
        if ($role !== 'teacher' && ! $u->roles()->whereRaw('LOWER(role_name)=?', ['teacher'])->exists()) {
            throw new AuthorizationException('Teacher role required.');
        }$t = Teacher::current()->where('user_id', $u->id)->where('school_id', $u->school_id)->first();
        if (! $t) {
            throw new AuthorizationException('Active teacher profile not found.');
        }

        return $t;
    }

    public function assignments(User $u)
    {
        $t = $this->teacher($u);

        return TeacherAssignment::current()->where('school_id', $u->school_id)->where('teacher_id', $t->id)->where('active', true)
            ->whereHas('academicYear', function ($query) use ($u) {
                $query->where('school_id', $u->school_id)
                    ->when(Schema::hasColumn('academic_years', 'active'), fn ($q) => $q->where('active', true))
                    ->when(Schema::hasColumn('academic_years', 'start_date'), fn ($q) => $q->whereDate('start_date', '<=', today()))
                    ->when(Schema::hasColumn('academic_years', 'end_date'), fn ($q) => $q->whereDate('end_date', '>=', today()));
            })->whereHas('term', function ($query) use ($u) {
                $query->where('school_id', $u->school_id)
                    ->when(Schema::hasColumn('terms', 'active'), fn ($q) => $q->where('active', true))
                    ->when(Schema::hasColumn('terms', 'start_date'), fn ($q) => $q->whereDate('start_date', '<=', today()))
                    ->when(Schema::hasColumn('terms', 'end_date'), fn ($q) => $q->whereDate('end_date', '>=', today()));
            })->with(['grade', 'stream', 'learningArea', 'academicYear', 'term'])->get();
    }

    public function requireAssignment(User $u, string $id): TeacherAssignment
    {
        $x = $this->assignments($u)->firstWhere('id', $id);
        if (! $x) {
            throw new AuthorizationException('Assignment is not available to this teacher.');
        }

        return $x;
    }

    public function learners(User $u)
    {
        $a = $this->assignments($u);

        return Learner::query()->where('school_id', $u->school_id)->where('active', true)->where('is_deleted', false)->where(function ($q) use ($a) {
            foreach ($a as $x) {
                $q->orWhere(fn ($z) => $z->where('grade_id', $x->grade_id)->where('stream_id', $x->stream_id));
            }
        })->with('grade', 'stream')->get();
    }

    public function requireLearner(User $u, string $id): Learner
    {
        $x = $this->learners($u)->firstWhere('id', $id);
        if (! $x) {
            throw new AuthorizationException('Learner is outside this teacher assignments.');
        }

        return $x;
    }

    public function requireClassTeacher(User $user, ?string $streamId = null): TeacherAssignment
    {
        $assignment = $this->assignments($user)->where('is_class_teacher', true)
            ->when($streamId, fn ($items) => $items->where('stream_id', $streamId))->first();
        if (! $assignment) {
            throw new AuthorizationException('An active class-teacher assignment is required.');
        }

        return $assignment;
    }

    public function requireStream(User $user, string $streamId): TeacherAssignment
    {
        $assignment = $this->assignments($user)->firstWhere('stream_id', $streamId);
        if (! $assignment) {
            throw new AuthorizationException('Stream is outside this teacher assignment scope.');
        }

        return $assignment;
    }
}
