<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\StudentElectionBallot;
use App\Models\User;
use App\Services\StudentElection\StudentElectionReconciliationService;
use App\Services\StudentElection\StudentElectionService;
use App\Services\StudentElection\StudentElectionVotingService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Tests\Support\StudentElectionConcurrencyFixture;
use Tests\TestCase;

class StudentElectionPrivacyReconciliationTest extends TestCase
{
    use DatabaseTransactions;

    private array $id = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['jwt.secret' => str_repeat('election-reconciliation-test-', 3)]);
        $this->id = StudentElectionConcurrencyFixture::create();
        foreach (['manage_student_elections', 'tally_student_elections', 'publish_student_election_results'] as $name) {
            $permission = DB::table('permissions')->where('permission_name', $name)->value('id');
            $this->assertNotNull($permission);
            DB::table('role_permissions')->insert(['id' => (string) Str::uuid(),
                'role_id' => $this->id['admin_role'], 'permission_id' => $permission]);
        }
    }

    private function castOne(): array
    {
        return app(StudentElectionVotingService::class)->vote(User::withoutGlobalScopes()->findOrFail($this->id['user']),
            $this->id['election'], $this->id['ep'], $this->id['candidate']);
    }

    private function closeFixture(): void
    {
        DB::table('student_elections')->where('id', $this->id['election'])->update(['status' => 'voting_closed']);
    }

    private function inspectFixture(): array
    {
        return app(StudentElectionReconciliationService::class)->inspect(
            User::withoutGlobalScopes()->findOrFail($this->id['admin']), $this->id['election']);
    }

    private function httpAs(User $user): void
    {
        Auth::forgetGuards();
        $token = JWTAuth::fromUser($user);
        JWTAuth::setToken($token);
        $this->withToken($token);
    }

    private function url(): string
    {
        return '/api/student-elections/'.$this->id['election'].'/reconciliation';
    }

    public function test_ballot_schema_has_no_cast_time_or_voter_session_link(): void
    {
        $columns = Schema::getColumnListing('student_election_ballots');
        foreach (['cast_at', 'created_at', 'voter_id', 'learner_id', 'user_id', 'device_id', 'session_id', 'request_id', 'submission_id'] as $column) {
            $this->assertNotContains($column, $columns);
        }
        $registry = Schema::getColumnListing('student_election_voters');
        $this->assertNotContains('voted_at', $registry);
        $this->assertNotContains('voting_token_hash', $registry);
    }

    public function test_receipt_has_no_time_ballot_identifier_or_choice(): void
    {
        $receipt = $this->castOne();
        $this->assertSame(['confirmed', 'election_id', 'election_position_id'], array_keys($receipt));
        $this->assertTrue($receipt['confirmed']);
    }

    public function test_participation_update_does_not_refresh_its_timestamp(): void
    {
        DB::table('student_election_voters')->where('id', $this->id['voter'])->update(['updated_at' => now()->subDays(2)]);
        $before = DB::table('student_election_voters')->where('id', $this->id['voter'])->value('updated_at');
        $this->castOne();
        $this->assertSame($before, DB::table('student_election_voters')->where('id', $this->id['voter'])->value('updated_at'));
    }

    public function test_matching_legacy_position_counts_reconcile_without_identifiers(): void
    {
        $this->castOne();
        $this->closeFixture();
        $report = $this->inspectFixture();
        $this->assertTrue($report['reconciled']);
        $this->assertSame(1, $report['positions'][0]['position_participations']);
        $this->assertSame(1, $report['positions'][0]['ballot_rows']);
        $this->assertSame(1, $report['positions'][0]['valid_selections']);
        $this->assertSame(1, $report['learners_with_any_position_participation']);
        $encoded = json_encode($report, JSON_THROW_ON_ERROR);
        foreach (['user', 'user2', 'learner', 'learner2', 'candidate', 'voter', 'voter2', 'admin'] as $key) {
            $this->assertStringNotContainsString($this->id[$key], $encoded);
        }
        $this->assertArrayNotHasKey('complete_submissions', $report);
    }

    public function test_ballot_without_participation_is_flagged_without_discarding_it(): void
    {
        $this->castOne();
        DB::table('student_election_voters')->where('id', $this->id['voter'])->update(['has_voted' => false]);
        $this->closeFixture();
        $report = $this->inspectFixture();
        $this->assertFalse($report['reconciled']);
        $this->assertContains('count_mismatch', $report['issues']);
        $this->assertSame(1, $report['positions'][0]['ballot_rows']);
    }

    public function test_participation_without_a_ballot_is_flagged(): void
    {
        DB::table('student_election_voters')->where('id', $this->id['voter'])->update(['has_voted' => true]);
        $this->closeFixture();
        $report = $this->inspectFixture();
        $this->assertFalse($report['reconciled']);
        $this->assertSame(1, $report['positions'][0]['participation_minus_ballots']);
    }

    public function test_invalid_ballots_are_reported_instead_of_silently_excluded(): void
    {
        $this->castOne();
        DB::table('student_election_ballots')->where('election_id', $this->id['election'])->update(['ballot_status' => 'invalid']);
        $this->closeFixture();
        $report = $this->inspectFixture();
        $this->assertFalse($report['reconciled']);
        $this->assertSame(1, $report['positions'][0]['ballot_integrity_errors']);
        $this->assertSame(1, $report['positions'][0]['ballot_rows']);
    }

    public function test_multiseat_policy_is_not_invented_by_reconciliation(): void
    {
        DB::table('student_election_positions')->where('id', $this->id['ep'])->update(['number_of_winners' => 2]);
        $this->closeFixture();
        $report = $this->inspectFixture();
        $this->assertFalse($report['reconciled']);
        $this->assertContains('unsupported_multiseat_policy', $report['issues']);
    }

    public function test_all_count_aggregates_use_one_sql_snapshot(): void
    {
        $this->closeFixture();
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            if (str_contains($query->sql, 'WITH parameters AS')) {
                $queries[] = $query->sql;
            }
        });
        $this->inspectFixture();
        $this->assertCount(1, $queries);
    }

    public function test_reconciliation_is_not_available_during_voting(): void
    {
        $this->httpAs(User::withoutGlobalScopes()->findOrFail($this->id['admin']));
        $this->getJson($this->url())->assertNotFound();
    }

    public function test_reconciliation_http_response_contains_aggregates_only(): void
    {
        $this->castOne();
        $this->closeFixture();
        $this->httpAs(User::withoutGlobalScopes()->findOrFail($this->id['admin']));
        $response = $this->getJson($this->url())->assertOk()->assertJsonPath('data.reconciled', true);
        foreach (['user', 'learner', 'candidate', 'voter'] as $key) {
            $this->assertStringNotContainsString($this->id[$key], $response->getContent());
        }
    }

    public function test_management_permission_does_not_imply_reconciliation_permission(): void
    {
        $this->closeFixture();
        DB::table('role_permissions')->where('role_id', $this->id['admin_role'])->where('permission_id',
            DB::table('permissions')->where('permission_name', 'tally_student_elections')->value('id'))->delete();
        $this->httpAs(User::withoutGlobalScopes()->findOrFail($this->id['admin']));
        $this->getJson($this->url())->assertForbidden();
    }

    public function test_foreign_election_cannot_be_reconciled(): void
    {
        $this->closeFixture();
        $copy = (array) DB::table('student_elections')->where('id', $this->id['election'])->first();
        $school = (string) Str::uuid();
        DB::table('schools')->insert(['id' => $school, 'school_name' => 'Other reconciliation school',
            'school_code' => 'ORS-'.Str::random(12), 'active' => true, 'is_deleted' => false]);
        $copy['id'] = (string) Str::uuid();
        $copy['school_id'] = $school;
        DB::table('student_elections')->insert($copy);
        $this->httpAs(User::withoutGlobalScopes()->findOrFail($this->id['admin']));
        $this->getJson('/api/student-elections/'.$copy['id'].'/reconciliation')->assertNotFound();
    }

    public function test_count_mismatch_blocks_tally_without_results_or_phase_changes(): void
    {
        DB::table('student_election_voters')->where('id', $this->id['voter'])->update(['has_voted' => true]);
        $this->closeFixture();
        try {
            app(StudentElectionService::class)->tally(
                $this->id['school'], $this->id['election'], $this->id['admin']);
            $this->fail('Unreconciled election was tallied.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('reconciliation', $exception->errors());
        }
        $this->assertSame(0, DB::table('student_election_results')->where('election_id', $this->id['election'])->count());
        $this->assertSame('voting_closed', DB::table('student_elections')->where('id', $this->id['election'])->value('status'));
    }

    public function test_count_mismatch_blocks_publication_and_appointments(): void
    {
        DB::table('student_election_voters')->where('id', $this->id['voter'])->update(['has_voted' => true]);
        DB::table('student_elections')->where('id', $this->id['election'])->update(['status' => 'tallied']);
        try {
            app(StudentElectionService::class)->publish(
                $this->id['school'], $this->id['election'], $this->id['admin']);
            $this->fail('Unreconciled election was published.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('reconciliation', $exception->errors());
        }
        $this->assertSame(0, DB::table('student_leadership_terms')->where('election_id', $this->id['election'])->count());
        $this->assertSame('tallied', DB::table('student_elections')->where('id', $this->id['election'])->value('status'));
    }

    public function test_invalid_election_identifier_is_not_a_database_error(): void
    {
        $this->httpAs(User::withoutGlobalScopes()->findOrFail($this->id['admin']));
        $this->getJson('/api/student-elections/not-a-uuid/reconciliation')->assertNotFound();
    }

    public function test_generic_transition_cannot_bypass_tally_or_publication(): void
    {
        foreach (['voting_closed' => 'tallied', 'tallied' => 'published'] as $from => $to) {
            DB::table('student_elections')->where('id', $this->id['election'])->update(['status' => $from]);
            try {
                app(StudentElectionService::class)->transition(
                    $this->id['school'], $this->id['election'], $to, $this->id['admin']);
                $this->fail('Generic transition bypassed reconciliation.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('status', $exception->errors());
            }
            $this->assertSame($from, DB::table('student_elections')->where('id', $this->id['election'])->value('status'));
        }
    }

    public function test_model_cannot_rewrite_an_accepted_choice(): void
    {
        $this->castOne();
        $ballot = StudentElectionBallot::where('election_id', $this->id['election'])->firstOrFail();
        $this->expectException(\LogicException::class);
        $ballot->update(['ballot_status' => 'invalid']);
    }

    public function test_model_cannot_delete_an_accepted_ballot(): void
    {
        $this->castOne();
        $ballot = StudentElectionBallot::where('election_id', $this->id['election'])->firstOrFail();
        $this->expectException(\LogicException::class);
        $ballot->delete();
    }
}
