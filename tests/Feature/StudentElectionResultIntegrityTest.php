<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\StudentElectionResult;
use App\Models\StudentElectionTallySnapshot;
use App\Models\User;
use App\Services\StudentElection\StudentElectionService;
use App\Services\StudentElection\StudentElectionVotingService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Tests\Support\StudentElectionConcurrencyFixture;
use Tests\TestCase;

class StudentElectionResultIntegrityTest extends TestCase
{
    use DatabaseTransactions;

    private array $id = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['jwt.secret' => str_repeat('election-declaration-http-', 3)]);
        $this->id = StudentElectionConcurrencyFixture::create();
        foreach (['manage_student_elections', 'tally_student_elections', 'publish_student_election_results'] as $name) {
            $permission = DB::table('permissions')->where('permission_name', $name)->value('id');
            $this->assertNotNull($permission);
            DB::table('role_permissions')->insert(['id' => (string) Str::uuid(), 'role_id' => $this->id['admin_role'], 'permission_id' => $permission]);
        }
        $this->id['candidate2'] = (string) Str::uuid();
        DB::table('student_election_candidates')->insert(['id' => $this->id['candidate2'], 'school_id' => $this->id['school'],
            'election_position_id' => $this->id['ep'], 'learner_id' => $this->id['learner2'], 'nomination_type' => 'manual', 'status' => 'approved', 'is_deleted' => false]);
    }

    private function electionService(): StudentElectionService
    {
        return app(StudentElectionService::class);
    }

    private function castFixtureSelection(string $user = 'user', string $candidate = 'candidate'): void
    {
        app(StudentElectionVotingService::class)->vote(User::withoutGlobalScopes()->findOrFail($this->id[$user]),
            $this->id['election'], $this->id['ep'], $this->id[$candidate]);
    }

    private function closeFixture(): void
    {
        $this->electionService()->transition($this->id['school'], $this->id['election'], 'voting_closed', $this->id['admin']);
    }

    private function tallyFixture()
    {
        return $this->electionService()->tally($this->id['school'], $this->id['election'], $this->id['admin']);
    }

    private function declareFixture()
    {
        return $this->electionService()->publish($this->id['school'], $this->id['election'], $this->id['publisher']);
    }

    private function clearWinner(): void
    {
        $this->castFixtureSelection();
        $this->castFixtureSelection('user2');
        $this->closeFixture();
        $this->tallyFixture();
    }

    private function assertFixtureDenied(callable $operation, string $field): void
    {
        try {
            $operation();
            $this->fail('Unsafe election operation succeeded.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($field, $exception->errors());
        }
        $this->assertSame(0, DB::table('student_leadership_terms')->where('election_id', $this->id['election'])->count());
    }

    private function tallySnapshot(): StudentElectionTallySnapshot
    {
        return StudentElectionTallySnapshot::where('election_id', $this->id['election'])->firstOrFail();
    }

    private function electionPhase(): string
    {
        return DB::table('student_elections')->where('id', $this->id['election'])->value('status');
    }

    public function test_tally_includes_approved_candidates_with_zero_votes(): void
    {
        $this->clearWinner();
        $rows = StudentElectionResult::where('election_id', $this->id['election'])->orderByDesc('vote_count')->get();
        $this->assertCount(2, $rows);
        $this->assertSame([2, 0], $rows->map(fn ($r) => (int) $r->vote_count)->all());
        $this->assertSame(['winner', 'not_elected'], $rows->pluck('result_status')->all());
        $this->assertSame('tallied', $this->electionPhase());
    }

    public function test_cutoff_tie_is_recorded_without_appointing_extra_winners(): void
    {
        $this->castFixtureSelection();
        $this->castFixtureSelection('user2', 'candidate2');
        $this->closeFixture();
        $this->tallyFixture();
        $this->assertSame(2, StudentElectionResult::where('election_id', $this->id['election'])->where('result_status', 'tied')->count());
        $this->assertSame(0, StudentElectionResult::where('election_id', $this->id['election'])->where('result_status', 'winner')->count());
        $this->assertFixtureDenied(fn () => $this->declareFixture(), 'declaration');
        $this->assertSame('tallied', $this->electionPhase());
    }

    public function test_zero_turnout_blocks_declaration(): void
    {
        $this->closeFixture();
        $this->tallyFixture();
        $this->assertSame(2, StudentElectionResult::where('election_id', $this->id['election'])->where('result_status', 'no_turnout')->count());
        $this->assertFixtureDenied(fn () => $this->declareFixture(), 'declaration');
    }

    public function test_unopposed_outcome_requires_an_explicit_policy(): void
    {
        DB::table('student_election_candidates')->where('id', $this->id['candidate2'])->delete();
        $this->castFixtureSelection();
        $this->closeFixture();
        $this->tallyFixture();
        $this->assertContains('unopposed_policy_required', $this->tallySnapshot()->payload['outcome']['positions'][0]['outcome']['blocked_reasons']);
        $this->assertFixtureDenied(fn () => $this->declareFixture(), 'declaration');
    }

    public function test_joint_winner_rule_cannot_enlarge_seats(): void
    {
        DB::table('student_elections')->where('id', $this->id['election'])->update(['tie_break_rule' => 'joint_winners']);
        $this->castFixtureSelection();
        $this->closeFixture();
        $this->assertFixtureDenied(fn () => $this->tallyFixture(), 'tie_break_rule');
        $this->assertSame('voting_closed', $this->electionPhase());
    }

    public function test_unapproved_multiseat_voting_method_remains_blocked(): void
    {
        DB::table('student_election_positions')->where('id', $this->id['ep'])->update(['number_of_winners' => 2]);
        $this->closeFixture();
        $this->assertFixtureDenied(fn () => $this->tallyFixture(), 'reconciliation');
    }

    public function test_verified_plurality_declaration_creates_one_appointment_and_receipt(): void
    {
        $this->clearWinner();
        $this->declareFixture();
        $this->assertSame('published', $this->electionPhase());
        $this->assertSame(1, DB::table('student_leadership_terms')->where('election_id', $this->id['election'])->count());
        $this->assertSame($this->id['learner'], DB::table('student_leadership_terms')->where('election_id', $this->id['election'])->value('learner_id'));
        $this->assertSame(1, DB::table('student_election_declarations')->where('election_id', $this->id['election'])->count());
        $this->assertSame(2, DB::table('student_election_audit_logs')->where('election_id', $this->id['election'])->whereIn('action', ['tallied', 'declared'])->count());
    }

    public function test_retries_do_not_rewrite_tally_or_duplicate_appointments_and_audit(): void
    {
        $this->clearWinner();
        $snapshot = $this->tallySnapshot()->id;
        $this->tallyFixture();
        $this->assertSame($snapshot, $this->tallySnapshot()->id);
        $this->declareFixture();
        $this->declareFixture();
        $this->assertSame(1, DB::table('student_leadership_terms')->where('election_id', $this->id['election'])->count());
        $this->assertSame(2, DB::table('student_election_audit_logs')->where('election_id', $this->id['election'])->whereIn('action', ['tallied', 'declared'])->count());
    }

    public function test_extra_winner_flag_is_rejected_before_any_appointment(): void
    {
        $this->clearWinner();
        DB::table('student_election_results')->where('candidate_id', $this->id['candidate2'])->update(['result_status' => 'winner']);
        $this->assertFixtureDenied(fn () => $this->declareFixture(), 'results');
    }

    public function test_changed_totals_are_rejected(): void
    {
        $this->clearWinner();
        DB::table('student_election_results')->where('candidate_id', $this->id['candidate'])->update(['vote_count' => 99]);
        $this->assertFixtureDenied(fn () => $this->declareFixture(), 'results');
    }

    public function test_missing_zero_vote_result_is_not_silently_recreated(): void
    {
        $this->clearWinner();
        DB::table('student_election_results')->where('candidate_id', $this->id['candidate2'])->delete();
        $this->assertFixtureDenied(fn () => $this->tallyFixture(), 'results');
        $this->assertFixtureDenied(fn () => $this->declareFixture(), 'results');
    }

    public function test_ballot_changes_after_tally_invalidate_declaration_evidence(): void
    {
        $this->clearWinner();
        $ballot = DB::table('student_election_ballots')->where('election_id', $this->id['election'])->value('id');
        DB::table('student_election_ballots')->where('id', $ballot)->update(['candidate_id' => $this->id['candidate2']]);
        $this->assertFixtureDenied(fn () => $this->declareFixture(), 'results');
    }

    public function test_changing_the_seat_count_after_tally_cannot_force_publication(): void
    {
        $this->clearWinner();
        DB::table('student_election_positions')->where('id', $this->id['ep'])->update(['number_of_winners' => 2]);
        $this->assertFixtureDenied(fn () => $this->declareFixture(), 'reconciliation');
    }

    public function test_missing_snapshot_requires_review_instead_of_rebuilding_evidence(): void
    {
        $this->clearWinner();
        DB::table('student_election_tally_snapshots')->where('election_id', $this->id['election'])->delete();
        $this->assertFixtureDenied(fn () => $this->declareFixture(), 'results');
    }

    private function failNextAudit(): void
    {
        $fail = true;
        DB::listen(function ($query) use (&$fail): void {
            $sql = strtolower(ltrim($query->sql));
            if ($fail && str_starts_with($sql, 'insert') && str_contains($sql, 'student_election_audit_logs')) {
                $fail = false;
                throw new \RuntimeException('Injected election audit failure.');
            }
        });
    }

    public function test_tally_audit_failure_rolls_back_results_snapshot_and_phase(): void
    {
        $this->castFixtureSelection();
        $this->closeFixture();
        $this->failNextAudit();
        try {
            $this->tallyFixture();
            $this->fail('Audit failure was ignored.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Injected election audit failure.', $exception->getMessage());
        }
        $this->assertSame('voting_closed', $this->electionPhase());
        $this->assertSame(0, DB::table('student_election_results')->where('election_id', $this->id['election'])->count());
        $this->assertSame(0, DB::table('student_election_tally_snapshots')->where('election_id', $this->id['election'])->count());
    }

    public function test_declaration_audit_failure_rolls_back_appointments_and_receipt(): void
    {
        $this->clearWinner();
        $this->failNextAudit();
        try {
            $this->declareFixture();
            $this->fail('Audit failure was ignored.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Injected election audit failure.', $exception->getMessage());
        }
        $this->assertSame('tallied', $this->electionPhase());
        $this->assertSame(0, DB::table('student_leadership_terms')->where('election_id', $this->id['election'])->count());
        $this->assertSame(0, DB::table('student_election_declarations')->where('election_id', $this->id['election'])->count());
    }

    public function test_result_models_cannot_be_edited_after_tally(): void
    {
        $this->clearWinner();
        $this->expectException(\LogicException::class);
        StudentElectionResult::where('candidate_id', $this->id['candidate'])->firstOrFail()->update(['rank' => 99]);
    }

    public function test_tally_snapshot_models_are_immutable(): void
    {
        $this->clearWinner();
        $this->expectException(\LogicException::class);
        $this->tallySnapshot()->update(['digest' => str_repeat('a', 64)]);
    }

    private function httpAsAdmin(): void
    {
        Auth::forgetGuards();
        $user = User::withoutGlobalScopes()->findOrFail($this->id['admin']);
        $token = JWTAuth::fromUser($user);
        JWTAuth::setToken($token);
        $this->withToken($token);
    }

    public function test_missing_declaration_permission_is_rejected_through_http(): void
    {
        $this->clearWinner();
        DB::table('role_permissions')->where('role_id', $this->id['admin_role'])->where('permission_id',
            DB::table('permissions')->where('permission_name', 'publish_student_election_results')->value('id'))->delete();
        $this->httpAsAdmin();
        $this->postJson('/api/student-elections/'.$this->id['election'].'/publish')->assertForbidden();
        $this->assertSame('tallied', $this->electionPhase());
    }

    public function test_declaration_checks_are_read_only_and_expose_no_voter_identity(): void
    {
        $this->clearWinner();
        $this->httpAsAdmin();
        $response = $this->getJson('/api/student-elections/'.$this->id['election'].'/declaration-checks')
            ->assertOk()->assertJsonPath('data.ready', true);
        foreach (['user', 'user2', 'learner', 'learner2', 'voter', 'voter2'] as $key) {
            $this->assertStringNotContainsString($this->id[$key], $response->getContent());
        }
        $this->assertSame('tallied', $this->electionPhase());
        $this->assertSame(0, DB::table('student_leadership_terms')->where('election_id', $this->id['election'])->count());
    }

    private function extraNominee(): string
    {
        $learner = (array) DB::table('learners')->where('id', $this->id['learner'])->first();
        $learner['id'] = (string) Str::uuid();
        $learner['user_id'] = null;
        $learner['admission_no'] = 'NOM-'.Str::random(12);
        DB::table('learners')->insert($learner);

        return $learner['id'];
    }

    public function test_changed_candidate_identity_cannot_redirect_an_appointment(): void
    {
        $this->clearWinner();
        DB::table('student_election_candidates')->where('id', $this->id['candidate'])->update(['learner_id' => $this->extraNominee()]);
        $this->assertFixtureDenied(fn () => $this->declareFixture(), 'results');
    }

    public function test_another_positions_tie_blocks_the_whole_declaration(): void
    {
        $position = (array) DB::table('student_leadership_positions')->where('id', $this->id['position'])->first();
        $position['id'] = (string) Str::uuid();
        $position['name'] = 'Second office';
        DB::table('student_leadership_positions')->insert($position);
        $ep = (array) DB::table('student_election_positions')->where('id', $this->id['ep'])->first();
        $ep['id'] = (string) Str::uuid();
        $ep['position_id'] = $position['id'];
        DB::table('student_election_positions')->insert($ep);
        $nominees = [];
        foreach (['user', 'user2'] as $key) {
            $candidate = (string) Str::uuid();
            DB::table('student_election_candidates')->insert(['id' => $candidate, 'school_id' => $this->id['school'],
                'election_position_id' => $ep['id'], 'learner_id' => $this->extraNominee(),
                'nomination_type' => 'manual', 'status' => 'approved', 'is_deleted' => false]);
            $voter = (array) DB::table('student_election_voters')->where('id', $this->id[$key === 'user' ? 'voter' : 'voter2'])->first();
            $voter['id'] = (string) Str::uuid();
            $voter['election_position_id'] = $ep['id'];
            DB::table('student_election_voters')->insert($voter);
            $nominees[$key] = $candidate;
        }
        $this->castFixtureSelection();
        $this->castFixtureSelection('user2');
        foreach ($nominees as $user => $candidate) {
            app(StudentElectionVotingService::class)->vote(User::withoutGlobalScopes()->findOrFail($this->id[$user]),
                $this->id['election'], $ep['id'], $candidate);
        }
        $this->closeFixture();
        $this->tallyFixture();
        $this->assertSame(1, StudentElectionResult::where('election_id', $this->id['election'])->where('result_status', 'winner')->count());
        $this->assertFixtureDenied(fn () => $this->declareFixture(), 'declaration');
    }

    public function test_existing_active_leader_is_not_silently_replaced_or_overbooked(): void
    {
        $this->clearWinner();
        DB::table('student_leadership_terms')->insert(['id' => (string) Str::uuid(), 'school_id' => $this->id['school'],
            'position_id' => $this->id['position'], 'learner_id' => $this->id['learner2'],
            'academic_year_id' => $this->id['academic_year'], 'starts_at' => today(), 'status' => 'active', 'appointment_type' => 'appointed']);
        $this->assertFixtureDenied(fn () => $this->declareFixture(), 'declaration');
        $this->assertSame(1, DB::table('student_leadership_terms')->where('school_id', $this->id['school'])->count());
    }

    public function test_unsupported_tie_rule_cannot_open_the_poll(): void
    {
        DB::table('student_elections')->where('id', $this->id['election'])->update(['status' => 'campaigning', 'tie_break_rule' => 'joint_winners']);
        $this->assertFixtureDenied(fn () => $this->electionService()->transition($this->id['school'], $this->id['election'], 'voting_open', $this->id['admin']), 'tie_break_rule');
        $this->assertSame('campaigning', $this->electionPhase());
    }

    public function test_unapproved_multiseat_method_cannot_open_the_poll(): void
    {
        DB::table('student_elections')->where('id', $this->id['election'])->update(['status' => 'campaigning']);
        DB::table('student_election_positions')->where('id', $this->id['ep'])->update(['number_of_winners' => 2]);
        $this->assertFixtureDenied(fn () => $this->electionService()->transition($this->id['school'], $this->id['election'], 'voting_open', $this->id['admin']), 'number_of_winners');
    }

    public function test_snapshot_checksum_corruption_blocks_declaration(): void
    {
        $this->clearWinner();
        DB::table('student_election_tally_snapshots')->where('election_id', $this->id['election'])->update(['digest' => str_repeat('0', 64)]);
        $this->assertFixtureDenied(fn () => $this->declareFixture(), 'results');
    }

    public function test_a_lost_appointment_is_not_recreated_by_a_declaration_retry(): void
    {
        $this->clearWinner();
        $this->declareFixture();
        DB::table('student_leadership_terms')->where('election_id', $this->id['election'])->delete();
        $this->assertFixtureDenied(fn () => $this->declareFixture(), 'declaration');
        $this->assertSame(2, DB::table('student_election_audit_logs')->where('election_id', $this->id['election'])->whereIn('action', ['tallied', 'declared'])->count());
    }

    public function test_tally_officer_cannot_declare_even_with_both_permissions(): void
    {
        $this->clearWinner();
        $this->assertFixtureDenied(fn () => $this->electionService()->publish($this->id['school'], $this->id['election'], $this->id['admin']), 'declaration');
        $this->assertSame('tallied', $this->electionPhase());
        $this->assertSame(0, DB::table('student_leadership_terms')->where('election_id', $this->id['election'])->count());
        $this->declareFixture();
        $this->assertSame('published', $this->electionPhase());
    }
}
