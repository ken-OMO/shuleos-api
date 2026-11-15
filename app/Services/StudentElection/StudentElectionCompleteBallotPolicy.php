<?php

declare(strict_types=1);

namespace App\Services\StudentElection;

use InvalidArgumentException;

final class StudentElectionCompleteBallotPolicy
{
    public function validate(array $slots, array $answers): array
    {
        if (! array_is_list($answers) || count($answers) !== count($slots) || $slots === []) {
            throw new InvalidArgumentException('Every eligible position requires one explicit answer.');
        }
        $expected = array_column($slots, null, 'slot_id');
        $accepted = [];
        foreach ($answers as $answer) {
            if (! is_array($answer) || array_diff(array_keys($answer), ['slot_id', 'choice', 'candidate_id']) !== []
                || ! is_string($answer['slot_id'] ?? null) || ! isset($expected[$answer['slot_id']]) || isset($accepted[$answer['slot_id']])) {
                throw new InvalidArgumentException('Ballot positions are missing, repeated or unsupported.');
            }
            $slot = $expected[$answer['slot_id']];
            $choice = $answer['choice'] ?? null;
            $candidate = $answer['candidate_id'] ?? null;
            $allowed = $slot['kind'] === 'confirmation' ? ['approve', 'reject', 'abstain'] : ['selection', 'abstain'];
            if (! in_array($choice, $allowed, true) || ($choice !== 'selection' && $candidate !== null)
                || ($choice === 'selection' && ! in_array($candidate, array_column($slot['candidates'], 'id'), true))) {
                throw new InvalidArgumentException('Answer does not match the frozen position and candidate set.');
            }
            $accepted[$answer['slot_id']] = ['choice' => $choice, 'candidate_id' => $candidate];
        }
        ksort($accepted, SORT_STRING);

        return $accepted;
    }
}
