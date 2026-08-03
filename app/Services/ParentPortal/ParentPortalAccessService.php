<?php

namespace App\Services\ParentPortal;

use App\Models\Guardian;
use App\Models\Learner;
use App\Models\LearnerParent;
use App\Models\SchoolSettings;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ParentPortalAccessService
{
    public function parent(User $user): Guardian
    {
        if (! $user->id || ! $user->school_id || ! $user->active || (Schema::hasColumn('users', 'is_deleted') && $user->is_deleted)) {
            throw new AuthorizationException('Active tenant membership required.');
        }
        $school = DB::table('schools')->where('id', $user->school_id)
            ->when(Schema::hasColumn('schools', 'active'), fn ($query) => $query->where('active', true))
            ->when(Schema::hasColumn('schools', 'is_deleted'), fn ($query) => $query->where('is_deleted', false))
            ->exists();
        if (! $school) {
            throw new AuthorizationException('Active school membership required.');
        }
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

        return LearnerParent::current()
            ->where('parent_id', $p->id)
            ->whereHas('learner', fn ($query) => $query->where('school_id', $user->school_id)->where('active', true)->where('is_deleted', false))
            ->with('learner.grade', 'learner.stream')
            ->orderByDesc('is_primary_contact')
            ->orderBy('created_at')
            ->get();
    }

    public function requireLinkedLearner(User $user, string $id): Learner
    {
        $link = $this->links($user)->firstWhere('learner_id', $id);
        if (! $link) {
            throw new AuthorizationException('Learner is not linked to this parent portal.');
        }

        return $link->learner;
    }

    public function requireLink(User $user, string $learnerId): LearnerParent
    {
        $link = $this->links($user)->firstWhere('learner_id', $learnerId);
        if (! $link) {
            throw new AuthorizationException('Learner is not linked to this parent portal.');
        }

        return $link;
    }

    public function defaultLearner(User $user, ?string $requestedLearnerId = null): Learner
    {
        if ($requestedLearnerId) {
            return $this->requireLinkedLearner($user, $requestedLearnerId);
        }

        $link = $this->links($user)->first();
        if (! $link) {
            throw new AuthorizationException('No active linked learners are available.');
        }

        return $link->learner;
    }
}
