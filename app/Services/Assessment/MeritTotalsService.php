<?php

declare(strict_types=1);

namespace App\Services\Assessment;

use App\Models\LearningAreaResult;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class MeritTotalsService
{
    /**
     * Calculate learner totals from supplied processed results and captured awards.
     * This method does not read or write database rows.
     */
    public function calculate(Collection $results, array $awards): Collection
    {
        $rows = $results->groupBy('learner_id')->map(function (Collection $items) use ($awards) {
            $learner = $items->first()->learner;

            if ($items->contains(fn (LearningAreaResult $item) => $item->grading_snapshot === null)) {
                throw ValidationException::withMessages([
                    'results' => 'Legacy processed results must be reprocessed to capture grading before merit generation.',
                ]);
            }

            if ($items->contains(fn (LearningAreaResult $item) => (bool) $item->is_absent)) {
                return [
                    'learner' => $learner,
                    'total_score' => null,
                    'maximum_marks' => round((float) $items->sum('maximum_marks'), 2),
                    'average_percentage' => null,
                    'total_points' => null,
                    'grading_system_id' => null,
                    'grading_scale_id' => null,
                    'ranking_method' => 'not_ranked_absent',
                    'grading_snapshot' => [
                        'format_version' => 1,
                        'rules_snapshot_id' => null,
                        'grading_system_id' => null,
                        'grading_scale_id' => null,
                        'grade_code' => null,
                        'grade_description' => null,
                        'points' => null,
                    ],
                ];
            }

            $score = round((float) $items->sum('marks_obtained'), 2);
            $maximum = round((float) $items->sum('maximum_marks'), 2);
            $average = round((float) $items->avg('percentage'), 2);
            $pointValues = $items->map(fn (LearningAreaResult $item) => $item->grading_snapshot['points'])
                ->filter(fn ($value) => $value !== null);
            $grade = $awards[$learner->id];

            return [
                'learner' => $learner, 'total_score' => $score, 'maximum_marks' => $maximum,
                'average_percentage' => $average, 'total_points' => $pointValues->isEmpty() ? null : (int) $pointValues->sum(),
                'grading_system_id' => $grade['grading_system_id'], 'grading_scale_id' => $grade['grading_scale_id'],
                'grading_snapshot' => $grade,
            ];
        })->values();

        return $rows;
    }
}
