<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\StudentElection\StudentElectionOutcomePolicy;
use PHPUnit\Framework\TestCase;

class StudentElectionOutcomePolicyTest extends TestCase
{
    private function evaluateCounts(array $counts, int $seats = 1): array
    {
        $rows = [];
        foreach ($counts as $id => $votes) {
            $rows[] = ['candidate_id' => $id, 'learner_id' => 'learner-'.$id, 'vote_count' => $votes];
        }

        return (new StudentElectionOutcomePolicy)->evaluate($rows, $seats);
    }

    private function winners(array $outcome): array
    {
        return array_values(array_column(array_filter($outcome['rows'], static fn ($r) => $r['result_status'] === 'winner'), 'candidate_id'));
    }

    public function test_single_seat_plurality_includes_zero_vote_candidates(): void
    {
        $outcome = $this->evaluateCounts(['a' => 3, 'b' => 0]);
        $this->assertSame(['a'], $this->winners($outcome));
        $this->assertCount(2, $outcome['rows']);
        $this->assertSame('not_elected', $outcome['rows'][1]['result_status']);
    }

    public function test_first_place_tie_never_creates_extra_winners(): void
    {
        $outcome = $this->evaluateCounts(['a' => 2, 'b' => 2, 'c' => 0]);
        $this->assertSame([], $this->winners($outcome));
        $this->assertSame(['cutoff_tie'], $outcome['blocked_reasons']);
        $this->assertSame(['tied', 'tied', 'not_elected'], array_column($outcome['rows'], 'result_status'));
    }

    public function test_ties_below_the_cutoff_do_not_block_the_winner(): void
    {
        $outcome = $this->evaluateCounts(['a' => 4, 'b' => 1, 'c' => 1]);
        $this->assertSame(['a'], $this->winners($outcome));
        $this->assertSame([], $outcome['blocked_reasons']);
        $this->assertSame([1, 2, 2], array_column($outcome['rows'], 'rank'));
    }

    public function test_generic_cutoff_arithmetic_preserves_strictly_resolved_seats(): void
    {
        $outcome = $this->evaluateCounts(['a' => 5, 'b' => 3, 'c' => 3, 'd' => 0], 2);
        $this->assertSame(['a'], $this->winners($outcome));
        $this->assertContains('cutoff_tie', $outcome['blocked_reasons']);
        $this->assertSame(['winner', 'tied', 'tied', 'not_elected'], array_column($outcome['rows'], 'result_status'));
    }

    public function test_zero_turnout_does_not_invent_winners(): void
    {
        $outcome = $this->evaluateCounts(['a' => 0, 'b' => 0]);
        $this->assertSame([], $this->winners($outcome));
        $this->assertSame(['no_turnout'], $outcome['blocked_reasons']);
    }

    public function test_unopposed_policy_is_not_silently_chosen(): void
    {
        $outcome = $this->evaluateCounts(['a' => 2]);
        $this->assertSame([], $this->winners($outcome));
        $this->assertContains('unopposed_policy_required', $outcome['blocked_reasons']);
    }

    public function test_candidate_input_order_cannot_break_a_tie(): void
    {
        $a = $this->evaluateCounts(['a' => 2, 'b' => 2, 'c' => 0]);
        $b = $this->evaluateCounts(['c' => 0, 'b' => 2, 'a' => 2]);
        $this->assertSame($a, $b);
    }

    public function test_many_count_patterns_never_exceed_capacity_or_elect_zero_votes(): void
    {
        for ($case = 0; $case < 100; $case++) {
            $counts = [];
            for ($i = 0; $i < 5; $i++) {
                $counts['candidate-'.$i] = ($case * ($i + 3) + $i * $i) % 11;
            }
            $seats = 1 + $case % 3;
            $outcome = $this->evaluateCounts($counts, $seats);
            $winners = $this->winners($outcome);
            $this->assertLessThanOrEqual($seats, count($winners));
            foreach ($winners as $winner) {
                $this->assertGreaterThan(0, $counts[$winner]);
            }
        }
    }

    public function test_snapshot_digest_is_independent_of_json_object_key_order(): void
    {
        $this->assertSame(StudentElectionOutcomePolicy::digest(['b' => ['y' => 2, 'x' => 1], 'a' => 0]),
            StudentElectionOutcomePolicy::digest(['a' => 0, 'b' => ['x' => 1, 'y' => 2]]));
    }

    public function test_invalid_seat_count_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->evaluateCounts(['a' => 1, 'b' => 0], 0);
    }

    public function test_negative_votes_are_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->evaluateCounts(['a' => -1, 'b' => 1]);
    }
}
