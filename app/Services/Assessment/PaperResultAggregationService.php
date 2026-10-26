<?php

declare(strict_types=1);

namespace App\Services\Assessment;

use Illuminate\Validation\ValidationException;

class PaperResultAggregationService
{
    public function __construct(
        private readonly PaperResultValueService $values
    ) {}

    /**
     * Calculate from supplied values without reading or writing database rows.
     */
    public function calculate(array $papers, array $results, float $expectedMaximum): array
    {
        $papers = collect($papers);
        $results = collect($results);
        $paperIds = $papers->pluck('id');

        if ($papers->isEmpty()
            || $paperIds->unique()->count() !== $papers->count()
            || $results->count() !== $papers->count()
            || $results->pluck('paper_id')->unique()->count() !== $papers->count()
            || $results->pluck('paper_id')->diff($paperIds)->isNotEmpty()) {
            throw ValidationException::withMessages([
                'results' => 'All expected paper results are required before processing.',
            ]);
        }

        $papersById = $papers->keyBy('id');
        $normalized = $results->map(fn (array $result): array => $this->values->normalize([
            'is_absent' => $result['is_absent'],
            'marks' => $result['marks'],
        ], (float) $papersById[$result['paper_id']]['max_marks']));

        $isAbsent = $normalized->contains(fn (array $value): bool => $value['is_absent']);
        $marksObtained = $isAbsent ? null : round((float) $normalized->sum('marks'), 2);
        $maximumMarks = round((float) $papers->sum('max_marks'), 2);

        if (! is_finite($expectedMaximum)
            || $maximumMarks <= 0
            || abs($maximumMarks - $expectedMaximum) > 0.001) {
            throw ValidationException::withMessages([
                'maximum_marks' => 'The paper maximum marks do not equal the exam learning area total marks.',
            ]);
        }

        if ($marksObtained > $maximumMarks) {
            throw ValidationException::withMessages([
                'marks_obtained' => 'Aggregated marks exceed the available maximum marks.',
            ]);
        }

        return [
            'is_absent' => $isAbsent,
            'marks_obtained' => $marksObtained,
            'maximum_marks' => $maximumMarks,
            'percentage' => $isAbsent
                ? null
                : round(($marksObtained / $maximumMarks) * 100, 2),
        ];
    }
}
