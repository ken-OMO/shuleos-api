<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\StudentElection\StudentElectionRoundPolicy;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class StudentElectionRoundPolicyTest extends TestCase
{
    public function test_confirmation_exhaustively_obeys_no_quorum_majority_of_decisive_votes(): void
    {
        $p = new StudentElectionRoundPolicy;
        $d = ['policy' => $p::VERSION, 'seats' => 1, 'kind' => 'confirmation', 'candidates' => [['candidate_id' => 'a', 'learner_id' => 'nominee']]];
        for ($approve = 0; $approve <= 5; $approve++) {
            for ($reject = 0; $reject <= 5; $reject++) {
                for ($abstain = 0; $abstain <= 3; $abstain++) {
                    $outcome = $p->evaluate($d, ['approve' => $approve, 'reject' => $reject, 'abstain' => $abstain,
                        'selections' => [], 'participations' => $approve + $reject + $abstain]);
                    $this->assertSame($approve > 0 && $approve > $reject, $outcome['rows'][0]['result_status'] === 'winner');
                    $this->assertSame($abstain, $outcome['abstentions']);
                }
            }
        }
    }

    public function test_only_cutoff_tied_candidates_enter_runoff(): void
    {
        $next = (new StudentElectionRoundPolicy)->next(['seats' => 1, 'rows' => [
            ['candidate_id' => 'b', 'result_status' => 'tied'], ['candidate_id' => 'a', 'result_status' => 'tied'],
            ['candidate_id' => 'c', 'result_status' => 'not_elected']], 'blocked_reasons' => ['cutoff_tie']]);
        $this->assertSame(['kind' => 'runoff', 'candidate_ids' => ['a', 'b']], $next);
    }

    public function test_resolved_office_cannot_be_repolled(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new StudentElectionRoundPolicy)->next(['seats' => 1, 'rows' => [['candidate_id' => 'a', 'result_status' => 'winner']], 'blocked_reasons' => []]);
    }

    public function test_multi_seat_stays_disabled(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new StudentElectionRoundPolicy)->next(['seats' => 2]);
    }

    public function test_rejected_confirmation_requires_new_nominations(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new StudentElectionRoundPolicy)->next(['seats' => 1, 'rows' => [['candidate_id' => 'a', 'result_status' => 'not_confirmed']], 'blocked_reasons' => ['confirmation_failed']]);
    }
}
