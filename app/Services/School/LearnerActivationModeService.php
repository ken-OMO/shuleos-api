<?php

declare(strict_types=1);

namespace App\Services\School;

use App\Models\Learner;
use App\Models\LearnerModeOfStudyHistory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LogicException;

class LearnerActivationModeService
{
    public function lockSchool(string $schoolId): void
    {
        DB::table('schools')
            ->where('id', $schoolId)
            ->where('active', true)
            ->where('is_deleted', false)
            ->sharedLock()->firstOrFail();
    }

    public function apply(
        Learner $learner,
        ?string $requestedMode,
        string $actorId,
        string $reason,
        ?string $effectiveDate = null
    ): void {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('Activation classification requires a transaction.');
        }

        $mode = $requestedMode ?? $learner->mode_of_study;

        if (! in_array($mode, ['day_scholar', 'boarder'], true)) {
            throw ValidationException::withMessages([
                'mode_of_study' => 'Choose Day Scholar or Boarder before activation.',
            ]);
        }

        app(SchoolOperatingModePolicy::class)->assertLearnerModeAllowed(
            (string) $learner->school_id,
            $mode
        );

        if ($mode === 'day_scholar' && DB::table('bed_allocations')
            ->where('school_id', $learner->school_id)
            ->where('learner_id', $learner->id)
            ->where('active', true)->exists()) {
            throw ValidationException::withMessages([
                'mode_of_study' => 'Release the active bed allocation before selecting Day Scholar.',
            ]);
        }

        if ($learner->mode_of_study === $mode) {
            return;
        }

        LearnerModeOfStudyHistory::query()->create([
            'id' => (string) Str::uuid(),
            'school_id' => $learner->school_id,
            'learner_id' => $learner->id,
            'from_mode' => $learner->mode_of_study,
            'to_mode' => $mode,
            'effective_date' => $effectiveDate ?? now()->toDateString(),
            'reason' => mb_substr($reason, 0, 500),
            'changed_by' => $actorId,
            'changed_at' => now(),
            'created_at' => now(),
        ]);

        // The enclosing lifecycle/restoration boundary saves and audits this.
        $learner->mode_of_study = $mode;
    }
}
