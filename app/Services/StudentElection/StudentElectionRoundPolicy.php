<?php

declare(strict_types=1);

namespace App\Services\StudentElection;

use InvalidArgumentException;

final class StudentElectionRoundPolicy
{
    public const VERSION = 'single-seat-resolution-v1';

    public function next(array $outcome): array
    {
        if (($outcome['seats'] ?? null) !== 1) {
            throw new InvalidArgumentException('Multi-seat voting remains disabled.');
        }
        if (in_array('confirmation_failed', $outcome['blocked_reasons'], true)) {
            throw new InvalidArgumentException('Rejected confirmation requires a new nomination workflow, not repeated confirmation.');
        }
        $rows = $outcome['rows'];
        if (count(array_filter($rows, static fn ($r) => $r['result_status'] === 'winner')) > 0) {
            throw new InvalidArgumentException('Resolved offices cannot be polled again.');
        }
        $tied = array_values(array_filter($rows, static fn ($r) => $r['result_status'] === 'tied'));
        if (count($tied) > 1) {
            $kind = 'runoff';
            $rows = $tied;
        } elseif (count($rows) === 1) {
            $kind = 'confirmation';
        } elseif (count($rows) > 1 && in_array('no_turnout', $outcome['blocked_reasons'], true)) {
            $kind = 'repeat_poll';
        } else {
            throw new InvalidArgumentException('A new nomination or reviewed resolution policy is required.');
        }
        $candidates = array_column($rows, 'candidate_id');
        sort($candidates, SORT_STRING);

        return ['kind' => $kind, 'candidate_ids' => $candidates];
    }

    public function evaluate(array $definition, array $counts): array
    {
        if (($definition['policy'] ?? '') !== self::VERSION || ($definition['seats'] ?? null) !== 1) {
            throw new InvalidArgumentException('Unsupported frozen policy.');
        }
        $rows = [];
        foreach ($definition['candidates'] as $candidate) {
            $rows[] = [...$candidate, 'vote_count' => (int) ($counts['selections'][$candidate['candidate_id']] ?? 0)];
        }
        if ($definition['kind'] !== 'confirmation') {
            return [...(new StudentElectionOutcomePolicy)->evaluate($rows, 1),
                'abstentions' => $counts['abstain'], 'participations' => $counts['participations']];
        }
        if (count($rows) !== 1) {
            throw new InvalidArgumentException('Confirmation needs exactly one candidate.');
        }
        $won = $counts['approve'] > 0 && $counts['approve'] > $counts['reject'];
        $rows[0]['vote_count'] = $counts['approve'];
        $rows[0]['rank'] = 1;
        $rows[0]['result_status'] = $won ? 'winner' : 'not_confirmed';

        return ['seats' => 1, 'rows' => $rows, 'blocked_reasons' => $won ? [] : [
            $counts['participations'] === 0 ? 'no_turnout' : 'confirmation_failed',
        ], 'approvals' => $counts['approve'], 'rejections' => $counts['reject'],
            'abstentions' => $counts['abstain'], 'participations' => $counts['participations']];
    }
}
