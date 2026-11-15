<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\StudentElection\StudentElectionReadService;
use App\Services\StudentElection\StudentElectionResultService;
use App\Services\StudentElection\StudentElectionRoundService;
use App\Services\StudentElection\StudentElectionService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Tests\Support\StudentElectionRoundFixture as F;
use Tests\TestCase;

class StudentElectionRoundIntegrityTest extends TestCase
{
    use DatabaseTransactions;

    private array $id = [];

    private function fixture(bool $unopposed = false, bool $noTurnout = false): array
    {
        $this->id = F::create($unopposed, $noTurnout);

        return F::schedule($this->id);
    }

    private function service(): StudentElectionRoundService
    {
        return app(StudentElectionRoundService::class);
    }

    private function denied(callable $operation, string $field): void
    {
        try {
            $operation();
            $this->fail('Unsafe election resolution succeeded.');
        } catch (ValidationException $error) {
            $this->assertArrayHasKey($field, $error->errors());
        }
    }

    private function publish(): void
    {
        app(StudentElectionService::class)->publish($this->id['school'], $this->id['election'], $this->id['publisher']);
    }

    public function test_runoff_keeps_original_electorate_and_does_not_rewrite_tied_results(): void
    {
        $r = $this->fixture();
        $this->assertSame('runoff', $r['kind']);
        $this->assertSame(2, DB::table('student_election_round_voters')->where('round_id', $r['id'])->count());
        $this->assertSame(2, DB::table('student_election_results')->where('election_id', $this->id['election'])->where('result_status', 'tied')->count());
        $this->denied(fn () => $this->publish(), 'declaration');
        F::open($this->id, $r);
        F::cast($this->id, $r, 'selection', false, $this->id['candidate']);
        F::cast($this->id, $r, 'selection', true, $this->id['candidate']);
        $outcome = F::finish($this->id, $r);
        $this->assertSame([], $outcome['outcome']['blocked_reasons']);
        $this->publish();
        $this->publish();
        $this->assertSame(1, DB::table('student_leadership_terms')->where('election_id', $this->id['election'])->count());
        $this->assertSame($this->id['learner'], DB::table('student_leadership_terms')->where('election_id', $this->id['election'])->value('learner_id'));
        $this->assertSame(2, DB::table('student_election_results')->where('election_id', $this->id['election'])->where('result_status', 'tied')->count());
        $effective = app(StudentElectionResultService::class)->effectiveRows($this->id['school'], $this->id['election']);
        $this->assertSame(1, $effective->where('result_status', 'winner')->count());
        $this->assertSame(2, (int) $effective->firstWhere('result_status', 'winner')->vote_count);
    }

    public function test_resolved_office_is_preserved_and_cannot_be_repolled(): void
    {
        $this->id = F::create(false, false, true);
        $rootRows = DB::table('student_election_results')->where('election_position_id', $this->id['resolved_ep'])->orderBy('id')->get()->toJson();
        $this->denied(fn () => $this->service()->create(F::user($this->id), $this->id['election'], $this->id['resolved_ep'],
            now()->addMinute()->toIso8601String(), now()->addMinutes(10)->toIso8601String()), 'round');
        $this->denied(fn () => $this->publish(), 'declaration');
        $r = F::schedule($this->id);
        F::open($this->id, $r);
        F::cast($this->id, $r, 'selection', false, $this->id['candidate']);
        F::finish($this->id, $r);
        $this->publish();
        $this->assertSame(2, DB::table('student_leadership_terms')->where('election_id', $this->id['election'])->count());
        $this->assertSame($rootRows, DB::table('student_election_results')->where('election_position_id', $this->id['resolved_ep'])->orderBy('id')->get()->toJson());
    }

    public function test_resolved_declaration_audit_failure_rolls_back_terms_and_receipt(): void
    {
        $r = $this->fixture(true);
        F::open($this->id, $r);
        F::cast($this->id, $r, 'approve');
        F::finish($this->id, $r);
        $once = true;
        DB::listen(function ($query) use (&$once): void {
            if ($once && str_starts_with(strtolower(ltrim($query->sql)), 'insert') && str_contains($query->sql, 'student_election_audit_logs')) {
                $once = false;
                throw new \RuntimeException('Injected declaration audit failure.');
            }
        });
        try {
            $this->publish();
            $this->fail('Expected declaration audit failure.');
        } catch (\RuntimeException $error) {
            $this->assertSame('Injected declaration audit failure.', $error->getMessage());
        }
        $this->assertSame('tallied', DB::table('student_elections')->where('id', $this->id['election'])->value('status'));
        $this->assertSame(0, DB::table('student_leadership_terms')->where('election_id', $this->id['election'])->count());
        $this->assertSame(0, DB::table('student_election_declarations')->where('election_id', $this->id['election'])->count());
        $this->assertSame('tallied', DB::table('student_election_rounds')->where('id', $r['id'])->value('status'));
    }

    public function test_round_tally_audit_failure_does_not_freeze_partial_outcome(): void
    {
        $r = $this->fixture(true);
        F::open($this->id, $r);
        F::cast($this->id, $r, 'approve');
        $this->service()->transition(F::user($this->id), $this->id['election'], $r['id'], 'closed');
        $once = true;
        DB::listen(function ($query) use (&$once): void {
            if ($once && str_starts_with(strtolower(ltrim($query->sql)), 'insert') && str_contains($query->sql, 'student_election_audit_logs')) {
                $once = false;
                throw new \RuntimeException('Injected tally audit failure.');
            }
        });
        try {
            $this->service()->tally(F::user($this->id), $this->id['election'], $r['id']);
            $this->fail('Expected strict audit failure.');
        } catch (\RuntimeException $error) {
            $this->assertSame('Injected tally audit failure.', $error->getMessage());
        }
        $record = DB::table('student_election_rounds')->where('id', $r['id'])->first();
        $this->assertSame('closed', $record->status);
        $this->assertNull($record->outcome);
        $this->assertNull($record->outcome_digest);
        $this->assertSame(1, DB::table('student_election_round_ballots')->where('round_id', $r['id'])->count());
    }

    public function test_repeated_tie_creates_linked_round_without_admin_winner_choice(): void
    {
        $r = $this->fixture();
        F::open($this->id, $r);
        F::cast($this->id, $r, 'selection', false, $this->id['candidate']);
        F::cast($this->id, $r, 'selection', true, $this->id['candidate2']);
        $result = F::finish($this->id, $r);
        $this->assertContains('cutoff_tie', $result['outcome']['blocked_reasons']);
        $this->denied(fn () => $this->publish(), 'declaration');
        $second = F::schedule($this->id);
        $this->assertSame($r['id'], $second['parent_round_id']);
        $this->assertSame(2, $second['sequence']);
        $this->assertSame('runoff', $second['kind']);
    }

    public function test_single_approval_confirms_unopposed_candidate_without_quorum(): void
    {
        $r = $this->fixture(true);
        $this->assertSame('confirmation', $r['kind']);
        F::open($this->id, $r);
        F::cast($this->id, $r, 'approve');
        $result = F::finish($this->id, $r);
        $this->assertSame(1, $result['outcome']['approvals']);
        $this->assertSame(0, $result['outcome']['rejections']);
        $this->assertSame('winner', $result['outcome']['rows'][0]['result_status']);
        $this->publish();
        $visible = app(StudentElectionReadService::class)->results(F::user($this->id, 'user'), $this->id['election']);
        $this->assertSame(1, $visible->where('result_status', 'winner')->count());
        $this->assertSame($r['id'], $visible->firstWhere('result_status', 'winner')->resolution_round_id);
        $this->assertSame(1, DB::table('student_leadership_terms')->where('election_id', $this->id['election'])->count());
    }

    public function test_equal_approval_and_rejection_does_not_confirm_or_allow_repeated_pressure_poll(): void
    {
        $r = $this->fixture(true);
        F::open($this->id, $r);
        F::cast($this->id, $r, 'approve');
        F::cast($this->id, $r, 'reject', true);
        $result = F::finish($this->id, $r);
        $this->assertContains('confirmation_failed', $result['outcome']['blocked_reasons']);
        $this->denied(fn () => $this->publish(), 'declaration');
        $this->denied(fn () => F::schedule($this->id), 'round');
    }

    public function test_abstentions_never_elect_unopposed_candidate(): void
    {
        $r = $this->fixture(true);
        F::open($this->id, $r);
        F::cast($this->id, $r, 'abstain');
        $result = F::finish($this->id, $r);
        $this->assertSame(1, $result['outcome']['abstentions']);
        $this->assertSame('not_confirmed', $result['outcome']['rows'][0]['result_status']);
        $this->denied(fn () => $this->publish(), 'declaration');
    }

    public function test_zero_turnout_never_wins_and_allows_a_linked_repeat_poll(): void
    {
        $r = $this->fixture(false, true);
        $this->assertSame('repeat_poll', $r['kind']);
        F::open($this->id, $r);
        $result = F::finish($this->id, $r);
        $this->assertContains('no_turnout', $result['outcome']['blocked_reasons']);
        $this->denied(fn () => $this->publish(), 'declaration');
        $this->assertSame($r['id'], F::schedule($this->id)['parent_round_id']);
    }

    public function test_duplicate_creation_cannot_branch_ancestry(): void
    {
        $r = $this->fixture();
        $this->denied(fn () => F::schedule($this->id), 'round');
        $this->assertSame(1, DB::table('student_election_rounds')->where('election_id', $this->id['election'])->count());
    }

    public function test_original_registry_change_blocks_resolution(): void
    {
        $this->fixture();
        DB::table('student_election_voters')->where('id', $this->id['voter2'])->update(['eligible' => false, 'has_voted' => false]);
        // Root reconciliation also notices the accepted vote/participation mismatch.
        $this->denied(fn () => $this->service()->index(F::user($this->id), $this->id['election']), 'reconciliation');
    }

    public function test_missing_original_freeze_requires_review_not_reconstruction(): void
    {
        $this->id = F::create();
        DB::table('student_election_electorates')->where('election_id', $this->id['election'])->delete();
        $this->denied(fn () => F::schedule($this->id), 'electorate');
    }

    public function test_attendance_is_checked_at_approval_and_again_at_vote(): void
    {
        $r = $this->fixture();
        F::open($this->id, $r);
        $token = F::approve($this->id, $r);
        DB::table('learner_attendance')->where('id', $this->id['attendance'])->update(['finalized' => false]);
        $this->denied(fn () => $this->service()->cast(F::user($this->id, 'user'), $this->id['election'], $r['id'], $token, 'selection', $this->id['candidate']), 'attendance');
        $this->denied(fn () => F::approve($this->id, $r), 'attendance');
        $this->assertSame(0, DB::table('student_election_round_ballots')->where('round_id', $r['id'])->count());
    }

    public function test_yesterday_presence_is_not_today_authorization(): void
    {
        $r = $this->fixture();
        F::open($this->id, $r);
        DB::table('learner_attendance')->where('id', $this->id['attendance'])->update(['attendance_date' => now('Africa/Nairobi')->subDay()->toDateString()]);
        $this->denied(fn () => F::approve($this->id, $r), 'attendance');
    }

    public function test_approval_expiry_is_independent_of_database_session_timezone(): void
    {
        $r = $this->fixture(true);
        F::open($this->id, $r);
        DB::statement("SET LOCAL TIME ZONE 'Pacific/Honolulu'");
        $token = F::approve($this->id, $r);
        $remaining = DB::selectOne('SELECT EXTRACT(EPOCH FROM (approval_expires_at - clock_timestamp())) AS seconds FROM student_election_round_voters WHERE round_id = ? AND learner_id = ?', [$r['id'], $this->id['learner']]);
        $this->assertGreaterThan(100.0, (float) $remaining->seconds);
        $this->assertLessThanOrEqual(121.0, (float) $remaining->seconds);
        $receipt = $this->service()->cast(F::user($this->id, 'user'), $this->id['election'], $r['id'], $token, 'approve');
        $this->assertTrue($receipt['confirmed']);
    }

    public function test_expired_approval_and_wrong_ballot_shape_are_rejected(): void
    {
        $r = $this->fixture(true);
        F::open($this->id, $r);
        $token = F::approve($this->id, $r);
        $this->denied(fn () => $this->service()->cast(F::user($this->id, 'user'), $this->id['election'], $r['id'], $token, 'selection', $this->id['candidate']), 'choice');
        DB::table('student_election_round_voters')->where('round_id', $r['id'])->update(['approval_expires_at' => now()->subMinute()->utc()->toIso8601String()]);
        $this->denied(fn () => $this->service()->cast(F::user($this->id, 'user'), $this->id['election'], $r['id'], $token, 'approve'), 'vote');
    }

    public function test_approval_is_consumed_without_persisting_vote_time_or_linkage(): void
    {
        $r = $this->fixture(true);
        F::open($this->id, $r);
        $token = F::approve($this->id, $r);
        $receipt = $this->service()->cast(F::user($this->id, 'user'), $this->id['election'], $r['id'], $token, 'reject');
        $this->assertSame(['confirmed', 'election_id', 'round_id'], array_keys($receipt));
        $this->denied(fn () => $this->service()->cast(F::user($this->id, 'user'), $this->id['election'], $r['id'], $token, 'approve'), 'vote');
        $v = DB::table('student_election_round_voters')->where('round_id', $r['id'])->where('learner_id', $this->id['learner'])->first();
        foreach (['approval_hash', 'approved_by', 'attendance_id', 'approval_expires_at'] as $field) {
            $this->assertNull($v->$field);
        }
        $columns = Schema::getColumnListing('student_election_round_ballots');
        foreach (['learner_id', 'user_id', 'voter_id', 'approval_hash', 'created_at', 'cast_at', 'attendance_id'] as $field) {
            $this->assertNotContains($field, $columns);
        }
    }

    public function test_tally_retry_keeps_one_frozen_outcome_and_one_tally_audit(): void
    {
        $r = $this->fixture(true);
        F::open($this->id, $r);
        F::cast($this->id, $r, 'approve');
        $a = F::finish($this->id, $r);
        $b = $this->service()->tally(F::user($this->id), $this->id['election'], $r['id']);
        $this->assertSame($a, $b);
        $this->assertSame(1, DB::table('student_election_audit_logs')->where('entity_id', $r['id'])->where('action', 'round_tallied')->count());
    }

    public function test_changed_round_totals_block_publication_instead_of_retallying(): void
    {
        $r = $this->fixture(true);
        F::open($this->id, $r);
        F::cast($this->id, $r, 'approve');
        F::finish($this->id, $r);
        DB::table('student_election_round_ballots')->where('round_id', $r['id'])->update(['choice' => 'reject']);
        $this->denied(fn () => $this->publish(), 'round');
    }

    public function test_changed_definition_digest_blocks_round_inspection(): void
    {
        $r = $this->fixture();
        DB::table('student_election_rounds')->where('id', $r['id'])->update(['definition_digest' => str_repeat('0', 64)]);
        $this->denied(fn () => $this->service()->index(F::user($this->id), $this->id['election']), 'round');
    }

    public function test_round_creation_audit_failure_rolls_back_round_and_registry(): void
    {
        $this->id = F::create();
        $once = true;
        DB::listen(function ($query) use (&$once): void {
            if ($once && str_starts_with(strtolower(ltrim($query->sql)), 'insert') && str_contains($query->sql, 'student_election_audit_logs')) {
                $once = false;
                throw new \RuntimeException('Injected round audit failure.');
            }
        });
        try {
            F::schedule($this->id);
            $this->fail('Expected strict audit failure.');
        } catch (\RuntimeException $error) {
            $this->assertSame('Injected round audit failure.', $error->getMessage());
        }
        $this->assertSame(0, DB::table('student_election_rounds')->where('election_id', $this->id['election'])->count());
        $this->assertSame(0, DB::table('student_election_round_voters')->count());
    }

    private function httpAsOfficer(): void
    {
        config(['jwt.secret' => str_repeat('round-integrity-http-', 4)]);
        Auth::forgetGuards();
        $token = JWTAuth::fromUser(F::user($this->id));
        JWTAuth::setToken($token);
        $this->withToken($token);
    }

    public function test_http_index_is_allowlisted_without_electorate_or_approval_data(): void
    {
        $r = $this->fixture();
        $this->httpAsOfficer();
        $response = $this->getJson('/api/student-elections/'.$this->id['election'].'/rounds')->assertOk();
        $this->assertStringContainsString($r['id'], $response->getContent());
        foreach (['learner', 'learner2', 'user', 'user2', 'voter', 'voter2'] as $key) {
            $this->assertStringNotContainsString($this->id[$key], $response->getContent());
        }
        $this->assertStringNotContainsString('approval_hash', $response->getContent());
    }

    public function test_http_missing_management_permission_cannot_create_round(): void
    {
        $this->id = F::create();
        $permission = DB::table('permissions')->where('permission_name', 'manage_election_voters')->value('id');
        DB::table('role_permissions')->where('role_id', $this->id['admin_role'])->where('permission_id', $permission)->delete();
        $this->httpAsOfficer();
        $this->postJson('/api/student-elections/'.$this->id['election'].'/rounds', ['election_position_id' => $this->id['ep'],
            'opens_at' => now()->addMinute()->toIso8601String(), 'closes_at' => now()->addMinutes(10)->toIso8601String()])->assertForbidden();
        $this->assertSame(0, DB::table('student_election_rounds')->where('election_id', $this->id['election'])->count());
    }

    public function test_http_foreign_election_does_not_leak_rounds(): void
    {
        $this->fixture();
        $foreign = F::create();
        $this->httpAsOfficer();
        $this->getJson('/api/student-elections/'.$foreign['election'].'/rounds')->assertNotFound();
    }

    public function test_round_tally_officer_cannot_declare_and_missing_round_audit_blocks_publication(): void
    {
        $r = $this->fixture();
        F::open($this->id, $r);
        F::cast($this->id, $r, 'selection', false, $this->id['candidate']);
        F::cast($this->id, $r, 'selection', true, $this->id['candidate']);
        // The root tally belongs to admin; let the other officer tally the runoff.
        $other = [...$this->id, 'admin' => $this->id['publisher']];
        F::finish($other, $r);
        $this->denied(fn () => $this->publish(), 'declaration');
        DB::table('student_election_audit_logs')->where('entity_id', $r['id'])->where('action', 'round_tallied')->delete();
        $this->denied(fn () => $this->publish(), 'declaration');
        $this->assertSame('tallied', DB::table('student_elections')->where('id', $this->id['election'])->value('status'));
    }
}
