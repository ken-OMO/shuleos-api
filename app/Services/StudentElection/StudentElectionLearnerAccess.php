<?php

namespace App\Services\StudentElection;

use App\Models\Learner;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

class StudentElectionLearnerAccess
{
    public function learner(User $user): Learner
    {
        if (! $user->active || $user->is_deleted || ! $user->school_id) {
            throw new AuthorizationException('Active school user required.');
        }
        $role = strtolower((string) $user->role?->role_name);
        if ($role !== 'learner' && ! $user->roles()->whereRaw('LOWER(role_name) = ?', ['learner'])->exists()) {
            throw new AuthorizationException('Learner role required.');
        }
        $learner = Learner::withoutGlobalScopes()
            ->where('user_id', $user->id)
            ->where('school_id', $user->school_id)
            ->where('active', true)
            ->where('portal_enabled', true)
            ->where('is_deleted', false)
            ->first();
        if (! $learner) {
            throw new AuthorizationException('Active linked learner profile not found.');
        }

        return $learner;
    }

    public function assertLearner(User $user, string $learnerId): Learner
    {
        $learner = $this->learner($user);
        if (! hash_equals($learner->id, $learnerId)) {
            throw new AuthorizationException('Learner record is outside the authenticated learner scope.');
        }

        return $learner;
    }
}
