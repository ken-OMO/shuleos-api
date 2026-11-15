<?php

declare(strict_types=1);

namespace App\Services\StudentElection;

use InvalidArgumentException;

final class StudentElectionOutcomePolicy
{
    // Ranking arithmetic does not approve a multi-seat voting method. The live
    // reconciliation service continues to reject that unresolved policy.
    public function evaluate(array $candidates, int $seats): array
    {
        if ($seats < 1) {
            throw new InvalidArgumentException('A positive seat count is required.');
        }
        $seen = [];
        foreach ($candidates as $candidate) {
            $id = $candidate['candidate_id'] ?? '';
            if (! is_string($id) || $id === '' || isset($seen[$id])
                || ! is_int($candidate['vote_count'] ?? null) || $candidate['vote_count'] < 0) {
                throw new InvalidArgumentException('Unique candidates and nonnegative integer vote counts are required.');
            }
            $seen[$id] = true;
        }
        usort($candidates, static fn ($a, $b) => ($b['vote_count'] <=> $a['vote_count']) ?: strcmp($a['candidate_id'], $b['candidate_id']));
        $reasons = [];
        $total = array_sum(array_column($candidates, 'vote_count'));
        if ($candidates === []) {
            $reasons[] = 'no_candidates';
        } elseif ($total === 0) {
            $reasons[] = 'no_turnout';
        } elseif (count($candidates) < $seats) {
            $reasons[] = 'insufficient_candidates';
        } elseif (count($candidates) === $seats) {
            $reasons[] = 'unopposed_policy_required';
        }
        $policyBlocked = $reasons !== [];
        $rows = [];
        for ($start = 0; $start < count($candidates); $start = $end) {
            $end = $start + 1;
            $votes = $candidates[$start]['vote_count'];
            while ($end < count($candidates) && $candidates[$end]['vote_count'] === $votes) {
                $end++;
            }
            $status = 'not_elected';
            if ($policyBlocked) {
                $status = $total === 0 ? 'no_turnout' : 'policy_pending';
            } elseif ($start < $seats && $votes === 0) {
                $status = 'policy_pending';
                $reasons[] = 'unfilled_seats';
            } elseif ($start < $seats && $end > $seats) {
                $status = 'tied';
                $reasons[] = 'cutoff_tie';
            } elseif ($end <= $seats) {
                $status = 'winner';
            }
            foreach (array_slice($candidates, $start, $end - $start) as $candidate) {
                $rows[] = [...$candidate, 'rank' => $start + 1, 'result_status' => $status];
            }
        }

        return ['seats' => $seats, 'rows' => $rows, 'blocked_reasons' => array_values(array_unique($reasons))];
    }

    public static function digest(array $data): string
    {
        $canonical = function ($value) use (&$canonical) {
            if (! is_array($value)) {
                return $value;
            }
            if (! array_is_list($value)) {
                ksort($value, SORT_STRING);
            }

            return array_map($canonical, $value);
        };

        return hash('sha256', json_encode($canonical($data), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}
