<?php

namespace App\Services\LearnerPortal;

use App\Models\HomeworkSubmission;
use App\Models\Learner;
use App\Models\ReportCard;
use App\Models\SchoolSettings;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

class LearnerPortalAccessService
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
        $settings = SchoolSettings::withoutGlobalScopes()->where('school_id', $user->school_id)->first();
        if ($settings && ! $settings->learner_portal_enabled) {
            throw new AuthorizationException('Learner portal is disabled.');
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

    public function homeworkRecord(User $user, string $assignmentId, bool $lock = false): object
    {
        $learner = $this->learner($user);
        $query = DB::table('homework_assignment_learners as assigned')
            ->join('homework_assignments as homework', 'homework.id', '=', 'assigned.assignment_id')
            ->where('assigned.school_id', $user->school_id)
            ->where('assigned.learner_id', $learner->id)
            ->where('assigned.assignment_id', $assignmentId)
            ->where('assigned.availability_status', 'available')
            ->whereIn('homework.status', ['published', 'closed', 'marking', 'marked'])
            ->where('homework.is_deleted', false)
            ->select('assigned.*');

        return ($lock ? $query->lockForUpdate() : $query)->firstOrFail();
    }

    public function submission(User $user, string $assignmentId, ?string $submissionId = null): HomeworkSubmission
    {
        $learner = $this->learner($user);
        $query = HomeworkSubmission::withoutGlobalScopes()
            ->where('school_id', $user->school_id)
            ->where('learner_id', $learner->id)
            ->where('assignment_id', $assignmentId);
        if ($submissionId) {
            $query->whereKey($submissionId);
        }

        return $query->latest('attempt_number')->firstOrFail();
    }

    public function requireReportCard(User $user, string $id): ReportCard
    {
        $learner = $this->learner($user);
        $settings = SchoolSettings::withoutGlobalScopes()->where('school_id', $user->school_id)->first();
        if ($settings && ! $settings->learner_portal_show_report_cards) {
            throw new AuthorizationException('Report cards are disabled.');
        }
        $card = ReportCard::withoutGlobalScopes()->whereKey($id)
            ->where('school_id', $user->school_id)
            ->where('learner_id', $learner->id)
            ->where('status', 'published')
            ->where('is_deleted', false)
            ->first();
        if (! $card) {
            throw new AuthorizationException('Published report card unavailable.');
        }

        return $card;
    }
}
