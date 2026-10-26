<?php

declare(strict_types=1);

namespace App\Services\Assessment;

use Illuminate\Support\Collection;

class MeritRankingService
{
    /**
     * Rank supplied learner totals without reading or writing database rows.
     */
    public function rank(Collection $rows): Collection
    {
        $rankedRows = $rows->reject(
            fn (array $row) => ($row['ranking_method'] ?? 'competition') === 'not_ranked_absent'
        );

        $schoolPositions = $this->positions($rankedRows);
        $gradePositions = $rankedRows
            ->groupBy(fn ($row) => $row['learner']->grade_id)
            ->flatMap(fn ($group) => $this->positions($group));
        $streamPositions = $rankedRows
            ->groupBy(fn ($row) => $row['learner']->stream_id)
            ->flatMap(fn ($group) => $this->positions($group));

        return $rows->map(function ($row) use ($schoolPositions, $gradePositions, $streamPositions) {
            $id = $row['learner']->id;
            $row['school_position'] = $schoolPositions->get($id);
            $row['grade_position'] = $gradePositions->get($id);
            $row['stream_position'] = $streamPositions->get($id);

            return $row;
        });
    }

    private function positions(Collection $rows): Collection
    {
        $sorted = $rows->sort(fn ($a, $b) => $this->compare($a, $b))->values();
        $positions = collect();
        $previous = null;
        foreach ($sorted as $index => $row) {
            $key = [$row['average_percentage'], $row['total_points'], $row['total_score']];
            $position = $previous !== null && $key === $previous['key'] ? $previous['position'] : $index + 1;
            $positions->put($row['learner']->id, $position);
            $previous = ['key' => $key, 'position' => $position];
        }

        return $positions;
    }

    private function compare(array $a, array $b): int
    {
        return $b['average_percentage'] <=> $a['average_percentage']
            ?: (($b['total_points'] ?? PHP_INT_MIN) <=> ($a['total_points'] ?? PHP_INT_MIN))
            ?: $b['total_score'] <=> $a['total_score'];
    }
}
