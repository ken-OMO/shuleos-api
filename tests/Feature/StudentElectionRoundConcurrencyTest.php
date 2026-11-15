<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\StudentElection\StudentElectionRoundService;
use Illuminate\Support\Facades\DB;
use Tests\Support\StudentElectionConcurrencyFixture;
use Tests\Support\StudentElectionConcurrencyRunner;
use Tests\Support\StudentElectionRoundFixture as F;
use Tests\TestCase;

class StudentElectionRoundConcurrencyTest extends TestCase
{
    private array $id = [];

    private array $round = [];

    private array $tokens = [];

    protected function setUp(): void
    {
        parent::setUp();
        StudentElectionConcurrencyFixture::assertIsolated();
        $this->assertSame(0, DB::transactionLevel());
        $this->id = F::create(true);
    }

    protected function tearDown(): void
    {
        try {
            if ($this->id !== []) {
                F::cleanup($this->id);
            }
        } finally {
            parent::tearDown();
        }
    }

    private function openRound(): void
    {
        $this->round = F::schedule($this->id);
        F::open($this->id, $this->round);
        $this->tokens['user'] = F::approve($this->id, $this->round);
        $this->tokens['user2'] = F::approve($this->id, $this->round, true);
    }

    private function job(string $operation, string $actor = 'admin'): array
    {
        return ['operation' => $operation, 'school' => $this->id['school'], 'election' => $this->id['election'],
            'ep' => $this->id['ep'], 'actor' => $this->id[$actor], 'round' => $this->round['id'] ?? null,
            'token' => $this->tokens[$actor] ?? null, 'choice' => 'approve'];
    }

    private function race(array $first, array $second, string $secondStatus = 'committed', bool $blocking = true, ?callable $after = null): array
    {
        $result = (new StudentElectionConcurrencyRunner)->run($first, $second, $blocking, null, $after);
        $this->assertTrue($result['separate_sessions']);
        $this->assertSame($blocking, $result['blocking_verified']);
        $this->assertSame('committed', $result['first']['status']);
        $this->assertSame($secondStatus, $result['second']['status']);

        return $result;
    }

    public function test_competing_round_creation_does_not_fork_the_position(): void
    {
        $job = $this->job('round_create') + ['opens' => now()->addMinute()->toIso8601String(), 'closes' => now()->addMinutes(10)->toIso8601String()];
        $this->race($job, $job, 'rejected');
        $this->assertSame(1, DB::table('student_election_rounds')->where('election_id', $this->id['election'])->count());
        $this->assertSame(1, DB::table('student_election_audit_logs')->where('election_id', $this->id['election'])->where('action', 'round_scheduled')->count());
    }

    public function test_distinct_round_voters_can_commit_in_parallel(): void
    {
        $this->openRound();
        $result = $this->race($this->job('round_vote', 'user'), $this->job('round_vote', 'user2'), 'committed', false);
        $this->assertTrue($result['parallel_commit_verified']);
        $this->assertSame(2, DB::table('student_election_round_ballots')->where('round_id', $this->round['id'])->count());
    }

    public function test_same_approval_cannot_produce_two_ballots(): void
    {
        $this->openRound();
        $result = $this->race($this->job('round_vote', 'user'), $this->job('round_vote', 'user'), 'rejected');
        $this->assertContains('vote', $result['second']['fields']);
        $this->assertSame(1, DB::table('student_election_round_ballots')->where('round_id', $this->round['id'])->count());
    }

    public function test_closure_waits_for_accepted_round_vote(): void
    {
        $this->openRound();
        $this->race($this->job('round_vote', 'user'), $this->job('round_close'));
        $this->assertSame('closed', DB::table('student_election_rounds')->where('id', $this->round['id'])->value('status'));
        $this->assertSame(1, DB::table('student_election_round_ballots')->where('round_id', $this->round['id'])->count());
    }

    public function test_vote_waiting_behind_closure_is_rejected(): void
    {
        $this->openRound();
        $result = $this->race($this->job('round_close'), $this->job('round_vote', 'user'), 'rejected');
        $this->assertContains('round', $result['second']['fields']);
        $this->assertSame(0, DB::table('student_election_round_ballots')->where('round_id', $this->round['id'])->count());
    }

    public function test_competing_round_tallies_freeze_only_once(): void
    {
        $this->openRound();
        F::cast($this->id, $this->round, 'approve');
        app(StudentElectionRoundService::class)->transition(F::user($this->id), $this->id['election'], $this->round['id'], 'closed');
        $this->race($this->job('round_tally'), $this->job('round_tally'));
        $this->assertSame(1, DB::table('student_election_audit_logs')->where('entity_id', $this->round['id'])->where('action', 'round_tallied')->count());
    }

    public function test_declaration_waits_for_round_tally_and_uses_resolved_winner(): void
    {
        $this->openRound();
        F::cast($this->id, $this->round, 'approve');
        app(StudentElectionRoundService::class)->transition(F::user($this->id), $this->id['election'], $this->round['id'], 'closed');
        $this->race($this->job('round_tally'), $this->job('publish'));
        $this->assertSame(1, DB::table('student_leadership_terms')->where('election_id', $this->id['election'])->count());
        $this->assertSame('published', DB::table('student_elections')->where('id', $this->id['election'])->value('status'));
    }

    public function test_approval_expiry_is_checked_after_actual_registry_wait(): void
    {
        $this->openRound();
        $hold = $this->job('hold_round_voter') + ['learner' => $this->id['learner']];
        $expires = now()->addSeconds(4)->startOfSecond();
        DB::table('student_election_round_voters')->where('round_id', $this->round['id'])->where('learner_id', $this->id['learner'])
            ->update(['approval_expires_at' => $expires->copy()->utc()->toIso8601String()]);
        $result = $this->race($hold, $this->job('round_vote', 'user'), 'rejected', true, function () use ($expires): void {
            $this->assertTrue(now()->lt($expires), 'Competitor must reach its lock before expiry.');
            while (now()->lte($expires)) {
                usleep(20000);
            }
        });
        $this->assertContains('vote', $result['second']['fields']);
        $this->assertSame(0, DB::table('student_election_round_ballots')->where('round_id', $this->round['id'])->count());
    }
}
