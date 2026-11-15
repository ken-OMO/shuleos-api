<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\StudentElectionBallot;
use App\Models\StudentElectionVoter;
use App\Models\User;
use App\Services\StudentElection\StudentElectionService;
use App\Services\StudentElection\StudentElectionVotingService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\Support\StudentElectionConcurrencyFixture;
use Tests\TestCase;

class StudentElectionVotingSafetyTest extends TestCase
{
    use DatabaseTransactions;

    private array $id = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->id = StudentElectionConcurrencyFixture::create();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function castFixtureVote(): array
    {
        $user = User::withoutGlobalScopes()->findOrFail($this->id['user']);

        return app(StudentElectionVotingService::class)->vote($user, $this->id['election'], $this->id['ep'], $this->id['candidate']);
    }

    private function assertNoAcceptedVote(): void
    {
        $this->assertSame(0, DB::table('student_election_ballots')->where('election_id', $this->id['election'])->count());
        $this->assertFalse((bool) DB::table('student_election_voters')->where('id', $this->id['voter'])->value('has_voted'));
    }

    public function test_opening_instant_is_inclusive(): void
    {
        $opens = DB::table('student_elections')->where('id', $this->id['election'])->value('voting_opens_at');
        Carbon::setTestNow(Carbon::parse($opens));
        $this->assertTrue($this->castFixtureVote()['confirmed']);
    }

    public function test_closing_instant_is_exclusive_and_has_no_partial_writes(): void
    {
        $closes = DB::table('student_elections')->where('id', $this->id['election'])->value('voting_closes_at');
        Carbon::setTestNow(Carbon::parse($closes));
        try {
            $this->castFixtureVote();
            $this->fail('Vote accepted exactly at cutoff.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('election', $exception->errors());
        }
        $this->assertNoAcceptedVote();
    }

    public function test_closed_election_cannot_accept_a_vote(): void
    {
        app(StudentElectionService::class)->transition($this->id['school'], $this->id['election'], 'voting_closed', $this->id['admin']);
        try {
            $this->castFixtureVote();
            $this->fail('Closed election accepted a vote.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('election', $exception->errors());
        }
        $this->assertNoAcceptedVote();
    }

    public function test_candidate_review_is_frozen_during_voting(): void
    {
        try {
            app(StudentElectionService::class)->review($this->id['school'], $this->id['candidate'], 'disqualified', $this->id['admin'], 'Must be rejected.');
            $this->fail('Candidate changed during voting.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('candidate', $exception->errors());
        }
        $this->assertSame('approved', DB::table('student_election_candidates')->where('id', $this->id['candidate'])->value('status'));
    }

    public function test_pre_voting_review_still_works(): void
    {
        DB::table('student_elections')->where('id', $this->id['election'])->update(['status' => 'nominations_open']);
        $candidate = app(StudentElectionService::class)->review($this->id['school'], $this->id['candidate'], 'rejected', $this->id['admin']);
        $this->assertSame('rejected', $candidate->status);
    }

    public function test_opening_at_the_cutoff_is_rejected(): void
    {
        DB::table('student_elections')->where('id', $this->id['election'])->update(['status' => 'campaigning']);
        Carbon::setTestNow(Carbon::parse(DB::table('student_elections')->where('id', $this->id['election'])->value('voting_closes_at')));
        try {
            app(StudentElectionService::class)->transition($this->id['school'], $this->id['election'], 'voting_open', $this->id['admin']);
            $this->fail('Voting opened at cutoff.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('status', $exception->errors());
        }
        $this->assertSame('campaigning', DB::table('student_elections')->where('id', $this->id['election'])->value('status'));
    }

    private function persistenceVeto(string $model, string $event): void
    {
        $original = $model::getEventDispatcher();
        $dispatcher = clone $original;
        $dispatcher->listen('eloquent.'.$event.': '.$model, static fn () => false);
        $model::setEventDispatcher($dispatcher);
        try {
            try {
                $this->castFixtureVote();
                $this->fail('Persistence veto was ignored.');
            } catch (\RuntimeException $exception) {
                $this->assertSame('Vote persistence failed.', $exception->getMessage());
            }
        } finally {
            $model::setEventDispatcher($original);
        }
        $this->assertNoAcceptedVote();
    }

    public function test_ballot_insert_veto_rolls_back_participation(): void
    {
        $this->persistenceVeto(StudentElectionBallot::class, 'creating');
    }

    public function test_participation_update_veto_rolls_back_ballot(): void
    {
        $this->persistenceVeto(StudentElectionVoter::class, 'updating');
    }

    public function test_random_marker_is_not_a_reusable_participation_token(): void
    {
        $this->castFixtureVote();
        $marker = DB::table('student_election_ballots')->where('election_id', $this->id['election'])->value('anonymous_ballot_token_hash');
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $marker);
        $this->assertFalse(Schema::hasColumn('student_election_voters', 'voting_token_hash'));
    }
}
