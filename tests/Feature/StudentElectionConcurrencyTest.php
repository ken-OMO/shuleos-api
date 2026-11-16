<?php

declare(strict_types=1);

namespace Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\Support\StudentElectionConcurrencyFixture;
use Tests\Support\StudentElectionConcurrencyRunner;
use Tests\TestCase;

class StudentElectionConcurrencyTest extends TestCase
{
    private array $id = [];

    protected function setUp(): void
    {
        parent::setUp();
        StudentElectionConcurrencyFixture::assertIsolated();
        $this->assertSame(0, DB::transactionLevel(), 'Race fixtures must be committed.');
        $this->id = StudentElectionConcurrencyFixture::create();
    }

    protected function tearDown(): void
    {
        try {
            if ($this->id !== []) {
                StudentElectionConcurrencyFixture::cleanup($this->id);
            }
        } finally {
            parent::tearDown();
        }
    }

    private function job(string $operation = 'vote', bool $second = false): array
    {
        return ['operation' => $operation, 'school' => $this->id['school'], 'election' => $this->id['election'],
            'ep' => $this->id['ep'], 'candidate' => $this->id['candidate'], 'voter' => $this->id['voter'],
            'actor' => in_array($operation, ['close', 'open', 'review'], true) ? $this->id['admin'] : $this->id[$second ? 'user2' : 'user']];
    }

    private function evidence(array $race, string $first, string $second, bool $blocked = true): void
    {
        $this->assertTrue($race['separate_sessions']);
        $this->assertSame($blocked, $race['blocking_verified']);
        $this->assertSame(! $blocked, $race['parallel_commit_verified']);
        $this->assertSame($first, $race['first']['status']);
        $this->assertSame($second, $race['second']['status']);
    }

    private function ballots(): int
    {
        return DB::table('student_election_ballots')->where('election_id', $this->id['election'])->count();
    }

    private function participation(): int
    {
        return DB::table('student_election_voters')->where('election_id', $this->id['election'])->where('has_voted', true)->count();
    }

    public function test_distinct_learners_commit_without_waiting_for_each_other(): void
    {
        $race = (new StudentElectionConcurrencyRunner)->run($this->job(), $this->job('vote', true), false);
        $this->evidence($race, 'committed', 'committed', false);
        $this->assertSame(2, $this->ballots());
        $this->assertSame(2, $this->participation());
    }

    public function test_duplicate_submission_waits_then_rejects_without_a_second_ballot(): void
    {
        $race = (new StudentElectionConcurrencyRunner)->run($this->job(), $this->job());
        $this->evidence($race, 'committed', 'rejected');
        $this->assertContains('vote', $race['second']['fields']);
        $this->assertSame(1, $this->ballots());
        $this->assertSame(1, $this->participation());
    }

    public function test_closure_waits_for_an_accepted_vote_to_commit(): void
    {
        $race = (new StudentElectionConcurrencyRunner)->run($this->job(), $this->job('close'));
        $this->evidence($race, 'committed', 'committed');
        $this->assertSame(1, $this->ballots());
        $this->assertSame(1, $this->participation());
        $this->assertSame('voting_closed', DB::table('student_elections')->where('id', $this->id['election'])->value('status'));
    }

    public function test_vote_waiting_behind_closure_is_rejected_after_close_commits(): void
    {
        $race = (new StudentElectionConcurrencyRunner)->run($this->job('close'), $this->job());
        $this->evidence($race, 'committed', 'rejected');
        $this->assertContains('election', $race['second']['fields']);
        $this->assertSame(0, $this->ballots());
        $this->assertSame(0, $this->participation());
    }

    public function test_rolled_back_vote_releases_registry_for_a_waiting_retry(): void
    {
        $race = (new StudentElectionConcurrencyRunner)->run([...$this->job(), 'rollback' => true], $this->job());
        $this->evidence($race, 'rolled_back', 'committed');
        $this->assertSame(1, $this->ballots());
        $this->assertSame(1, $this->participation());
    }

    public function test_cutoff_is_rechecked_after_a_registry_lock_wait(): void
    {
        $cutoff = null;
        $race = (new StudentElectionConcurrencyRunner)->run($this->job('hold_voter'), $this->job(), true,
            function () use (&$cutoff): void {
                $cutoff = CarbonImmutable::now()->addSeconds(6)->startOfSecond();
                DB::table('student_elections')->where('id', $this->id['election'])
                    ->update(['voting_closes_at' => $cutoff->format('Y-m-d H:i:s')]);
            },
            function () use (&$cutoff): void {
                $this->assertTrue(CarbonImmutable::now()->lt($cutoff), 'Competitor must reach the lock before the cutoff.');
                while (CarbonImmutable::now()->lte($cutoff)) {
                    usleep(20000);
                }
            });
        $this->evidence($race, 'committed', 'rejected');
        $this->assertContains('election', $race['second']['fields']);
        $this->assertSame(0, $this->ballots());
        $this->assertSame(0, $this->participation());
    }

    public function test_competing_closures_serialize_and_do_not_reopen_the_election(): void
    {
        $race = (new StudentElectionConcurrencyRunner)->run($this->job('close'), $this->job('close'));
        $this->evidence($race, 'committed', 'rejected');
        $this->assertSame('voting_closed', DB::table('student_elections')->where('id', $this->id['election'])->value('status'));
        $this->assertSame(0, $this->ballots());
    }

    public function test_review_waiting_behind_opening_cannot_change_the_frozen_candidate(): void
    {
        DB::table('student_elections')->where('id', $this->id['election'])->update(['status' => 'campaigning']);
        $race = (new StudentElectionConcurrencyRunner)->run($this->job('open'), $this->job('review'));
        $this->evidence($race, 'committed', 'rejected');
        $this->assertContains('candidate', $race['second']['fields']);
        $this->assertSame('approved', DB::table('student_election_candidates')->where('id', $this->id['candidate'])->value('status'));
    }

    public function test_competing_voter_generation_is_idempotent_and_audited(): void
    {
        DB::table('student_elections')->where('id', $this->id['election'])->update(['status' => 'draft']);
        DB::table('student_election_voters')->where('election_id', $this->id['election'])->delete();
        $job = [...$this->job(), 'operation' => 'generate_voters', 'actor' => $this->id['admin']];
        $race = (new StudentElectionConcurrencyRunner)->run($job, $job);
        $this->evidence($race, 'committed', 'committed');
        $this->assertSame(2, DB::table('student_election_voters')->where('election_id', $this->id['election'])->count());
        $counts = DB::table('student_election_audit_logs')->where('election_id', $this->id['election'])
            ->where('action', 'voters_generated')->get()->map(fn ($row) => json_decode($row->metadata, true)['inserted'])->sort()->values()->all();
        $this->assertSame([0, 2], $counts);
    }

    public function test_competing_position_creation_commits_only_one_position(): void
    {
        $job = [...$this->job(), 'operation' => 'create_position', 'actor' => $this->id['admin']];
        $race = (new StudentElectionConcurrencyRunner)->run($job, $job);
        $this->evidence($race, 'committed', 'rejected');
        $this->assertSame(1, DB::table('student_leadership_positions')->where('school_id', $this->id['school'])->where('name', 'Race position')->count());
        $this->assertSame(1, DB::table('student_election_audit_logs')->where('school_id', $this->id['school'])->where('action', 'position_created')->count());
    }
}
