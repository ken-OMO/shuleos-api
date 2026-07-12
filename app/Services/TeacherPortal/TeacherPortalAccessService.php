<?php

namespace App\Services\TeacherPortal;

use App\Models\Learner;
use App\Models\Teacher;
use App\Models\TeacherAssignment;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

class TeacherPortalAccessService
{
    public function teacher(User $u): Teacher
    {
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

        return TeacherAssignment::current()->where('school_id', $u->school_id)->where('teacher_id', $t->id)->where('active', true)->with(['grade', 'stream', 'learningArea', 'academicYear', 'term'])->get();
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
}
