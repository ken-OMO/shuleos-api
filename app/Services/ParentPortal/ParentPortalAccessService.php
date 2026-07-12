<?php

namespace App\Services\ParentPortal;

use App\Models\Guardian;
use App\Models\Learner;
use App\Models\LearnerParent;
use App\Models\SchoolSettings;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

class ParentPortalAccessService
{
    public function parent(User $user): Guardian
    {
        $settings = SchoolSettings::where('school_id', $user->school_id)->first();
        if ($settings && ! $settings->parent_portal_enabled) {
            throw new AuthorizationException('Parent portal is disabled for this school.');
        }
        $role = strtolower((string) $user->role?->role_name);
        if ($role !== 'parent' && ! $user->roles()->whereRaw('LOWER(role_name) = ?', ['parent'])->exists()) {
            throw new AuthorizationException('Parent role required.');
        }$parent = Guardian::current()->where('user_id', $user->id)->where('school_id', $user->school_id)->first();
        if (! $parent) {
            throw new AuthorizationException('Active parent profile not found.');
        }

        return $parent;
    }

    public function links(User $user)
    {
        $p = $this->parent($user);

        return LearnerParent::current()->where('parent_id', $p->id)->whereHas('learner', fn ($q) => $q->where('school_id', $user->school_id)->where('active', true)->where('is_deleted', false))->with('learner.grade', 'learner.stream')->get();
    }

    public function requireLinkedLearner(User $user, string $id): Learner
    {
        $link = $this->links($user)->firstWhere('learner_id', $id);
        if (! $link) {
            throw new AuthorizationException('Learner is not linked to this parent portal.');
        }

        return $link->learner;
    }
}
