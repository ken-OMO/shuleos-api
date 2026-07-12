<?php

namespace App\Services\LearnerPortal;

use App\Models\Learner;
use App\Models\ReportCard;
use App\Models\SchoolSettings;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

class LearnerPortalAccessService
{
    public function learner(User $u): Learner
    {
        if (! $u->active) {
            throw new AuthorizationException('Inactive user.');
        }$role = strtolower((string) $u->role?->role_name);
        if ($role !== 'learner' && ! $u->roles()->whereRaw('LOWER(role_name)=?', ['learner'])->exists()) {
            throw new AuthorizationException('Learner role required.');
        }$settings = SchoolSettings::where('school_id', $u->school_id)->first();
        if ($settings && ! $settings->learner_portal_enabled) {
            throw new AuthorizationException('Learner portal is disabled.');
        }$l = Learner::portalActive()->where('user_id', $u->id)->where('school_id', $u->school_id)->first();
        if (! $l) {
            throw new AuthorizationException('Active linked learner profile not found.');
        }

        return $l;
    }

    public function requireReportCard(User $u, string $id)
    {
        $l = $this->learner($u);
        $settings = SchoolSettings::where('school_id', $u->school_id)->first();
        if ($settings && ! $settings->learner_portal_show_report_cards) {
            throw new AuthorizationException('Report cards are disabled.');
        }$x = ReportCard::current()->whereKey($id)->where('school_id', $u->school_id)->where('learner_id', $l->id)->where('status', 'published')->first();
        if (! $x) {
            throw new AuthorizationException('Published report card unavailable.');
        }

        return $x;
    }
}
