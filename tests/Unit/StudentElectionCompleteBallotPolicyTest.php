<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\StudentElection\StudentElectionCompleteBallotPolicy;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class StudentElectionCompleteBallotPolicyTest extends TestCase
{
    private function slots(): array
    {
        return [['slot_id' => 'a', 'kind' => 'plurality', 'candidates' => [['id' => 'x'], ['id' => 'y']]],
            ['slot_id' => 'b', 'kind' => 'confirmation', 'candidates' => [['id' => 'z']]]];
    }

    public function test_every_valid_choice_pair_is_accepted_without_implicit_answers(): void
    {
        $p = new StudentElectionCompleteBallotPolicy;
        foreach ([['selection', 'x'], ['selection', 'y'], ['abstain', null]] as [$choice,$candidate]) {
            foreach (['approve', 'reject', 'abstain'] as $confirmation) {
                $answers = [['slot_id' => 'b', 'choice' => $confirmation, 'candidate_id' => null], ['slot_id' => 'a', 'choice' => $choice, 'candidate_id' => $candidate]];
                $accepted = $p->validate($this->slots(), $answers);
                $this->assertSame(['a', 'b'], array_keys($accepted));
                $this->assertSame($choice, $accepted['a']['choice']);
                $this->assertSame($confirmation, $accepted['b']['choice']);
            }
        }
    }

    public function test_missing_position_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new StudentElectionCompleteBallotPolicy)->validate($this->slots(), [['slot_id' => 'a', 'choice' => 'abstain']]);
    }

    public function test_duplicate_position_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new StudentElectionCompleteBallotPolicy)->validate($this->slots(), [['slot_id' => 'a', 'choice' => 'abstain'], ['slot_id' => 'a', 'choice' => 'abstain']]);
    }

    public function test_cross_position_candidate_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new StudentElectionCompleteBallotPolicy)->validate($this->slots(), [['slot_id' => 'a', 'choice' => 'selection', 'candidate_id' => 'z'], ['slot_id' => 'b', 'choice' => 'approve']]);
    }

    public function test_abstention_cannot_hide_a_selection(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new StudentElectionCompleteBallotPolicy)->validate($this->slots(), [['slot_id' => 'a', 'choice' => 'abstain', 'candidate_id' => 'x'], ['slot_id' => 'b', 'choice' => 'approve']]);
    }

    public function test_confirmation_cannot_be_candidate_selection(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new StudentElectionCompleteBallotPolicy)->validate($this->slots(), [['slot_id' => 'a', 'choice' => 'abstain'], ['slot_id' => 'b', 'choice' => 'selection', 'candidate_id' => 'z']]);
    }

    public function test_extra_answer_or_unrecognized_field_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new StudentElectionCompleteBallotPolicy)->validate($this->slots(), [['slot_id' => 'a', 'choice' => 'abstain', 'learner_id' => 'forged'], ['slot_id' => 'b', 'choice' => 'approve']]);
    }
}
