<?php

declare(strict_types=1);

namespace App\Services\School;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SchoolOperatingModePolicy
{
    public function assertBoardingAllowed(string $schoolId): void
    {
        $mode = DB::table('schools')
            ->where('id', $schoolId)
            ->where('active', true)
            ->where('is_deleted', false)
            ->value('operating_mode');

        abort_unless(
            in_array($mode, ['boarding', 'day_and_boarding'], true),
            403,
            'Boarding is unavailable for the selected school operating mode.'
        );
    }

    public function assertLearnerModeAllowed(string $schoolId, string $learnerMode): void
    {
        $mode = DB::table('schools')
            ->where('id', $schoolId)
            ->where('active', true)
            ->where('is_deleted', false)
            ->sharedLock()->value('operating_mode');

        $allowed = match ($mode) {
            'day' => ['day_scholar'],
            'boarding' => ['boarder'],
            'day_and_boarding' => ['day_scholar', 'boarder'],
            default => [],
        };

        if (! in_array($learnerMode, $allowed, true)) {
            throw ValidationException::withMessages([
                'mode_of_study' => 'Select a learner classification supported by the school operating mode.',
            ]);
        }
    }
}
