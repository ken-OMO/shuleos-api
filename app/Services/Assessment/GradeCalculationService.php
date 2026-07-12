<?php

namespace App\Services\Assessment;

use App\Models\GradingScale;
use App\Models\GradingSystem;
use App\Models\Learner;
use Illuminate\Validation\ValidationException;

class GradeCalculationService
{
    /**
     * @return array{grading_system: GradingSystem, grading_scale: GradingScale}
     */
    public function calculate(Learner $learner, string $schoolId, float $percentage): array
    {
        $educationLevelId = $learner->grade?->education_level_id;

        if (! $educationLevelId) {
            throw ValidationException::withMessages([
                'learner_id' => 'The learner grade does not resolve to an education level.',
            ]);
        }

        $systems = GradingSystem::query()
            ->where('school_id', $schoolId)
            ->where('education_level_id', $educationLevelId)
            ->where('active', true)
            ->get();

        if ($systems->count() !== 1) {
            throw ValidationException::withMessages([
                'grading_system' => 'Exactly one active grading system is required for the learner education level.',
            ]);
        }

        $system = $systems->first();
        $scales = GradingScale::query()
            ->where('grading_system_id', $system->id)
            ->matchingPercentage($percentage)
            ->get();

        if ($scales->count() !== 1) {
            throw ValidationException::withMessages([
                'grading_scale' => 'The percentage must match exactly one grading scale.',
            ]);
        }

        return [
            'grading_system' => $system,
            'grading_scale' => $scales->first(),
        ];
    }
}
