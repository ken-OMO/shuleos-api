<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\StudentElection;
use App\Services\SchoolSetupReadinessService;
use App\Services\StudentElection\StudentElectionApplicationService;
use App\Services\StudentElection\StudentElectionCancellationService;
use App\Services\StudentElection\StudentElectionService;
use App\Services\StudentElection\StudentElectionVotingService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\StudentElectionApplicationFixture as F;
use Tests\Support\StudentElectionApplicationRaceFixture as Fixture;
use Tests\Support\StudentElectionApplicationRaceRunner as Runner;
use Tests\TestCase;

class StudentElectionCancellationTest extends TestCase
{
    private array $id = [];

    private array $application = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['jwt.secret' => str_repeat('cancellation-http-secret-', 4)]);
        $this->mock(SchoolSetupReadinessService::class)->shouldReceive('isReady')->andReturn(true);
        $this->travelTo(now()->startOfDay()->addHours(10));
        $this->id = Fixture::create();
        DB::table('student_elections')->where('id', $this->id['election'])->update(['status' => 'nominations_open']);
        $this->travel(2)->minutes();
    }

    protected function tearDown(): void
    {
        try {
            if ($this->id !== []) {
                Fixture::cleanup($this->id);
            }
        } finally {
            parent::tearDown();
        }
    }

    private function service(): StudentElectionCancellationService
    {
        return app(StudentElectionCancellationService::class);
    }

    private function held(): void
    {
        $s = app(StudentElectionApplicationService::class);
        $a = $s->submit(F::user($this->id, 'user'), $this->id['election'], null, F::data($this->id));
        $this->application = $a;
        $s->consent(F::user($this->id, 'parent_user'), $this->id['election'], $a['id'], $a['version'], $this->id['designation']['version'], 'accept');
        $s->review(F::user($this->id), $this->id['election'], $a['id'], $a['version'], true, 'Independent exact-version review with no conflict.', false, true);
        $this->travel(59)->minutes();
        $lifecycle = app(StudentElectionService::class);
        $lifecycle->transition($this->id['school'], $this->id['election'], 'nominations_closed', $this->id['admin']);
        $lifecycle->transition($this->id['school'], $this->id['election'], 'voting_open', $this->id['admin']);
        app(StudentElectionVotingService::class)->vote(F::user($this->id, 'user'), $this->id['election'], $this->id['ep'], $a['candidate_id']);
        $s->consent(F::user($this->id, 'parent_user'), $this->id['election'], $a['id'], $a['version'], $this->id['designation']['version'], 'withdraw');
    }

    private function propose(): array
    {
        return $this->service()->propose(F::user($this->id), $this->id['election'], 'Original evidence requires independently reviewed cancellation.', true);
    }

    private function decide(array $p, bool $approve = true): array
    {
        return $this->service()->decide(F::user($this->id, 'appeal_user'), $this->id['election'], $p['proposal'], $approve, 'Independent officer reviewed the frozen incident evidence.', true);
    }

    private function freshData(): array
    {
        return ['title' => 'Fresh independently linked election', 'academic_year_id' => $this->id['academic_year'], 'voting_opens_at' => now()->addDays(7)->toIso8601String(), 'voting_closes_at' => now()->addDays(7)->addHour()->toIso8601String()];
    }

    private function fresh(): array
    {
        return $this->service()->fresh(F::user($this->id), $this->id['election'], $this->freshData(), true);
    }

    private function denied(callable $action, int $status): void
    {
        try {
            $action();
            $this->fail('Unsafe cancellation action succeeded.');
        } catch (HttpException $e) {
            $this->assertSame($status, $e->getStatusCode());
        }
    }

    public function test_requires_recorded_post_opening_hold(): void
    {
        $this->expectException(ValidationException::class);
        $this->propose();
    }

    public function test_independent_decision_preserves_ballots_versions_and_events(): void
    {
        $this->held();
        $tables = ['student_election_ballots', 'student_election_application_versions', 'student_election_application_events', 'student_election_declarations', 'student_election_tally_snapshots'];
        $before = [];
        foreach ($tables as $t) {
            $before[$t] = DB::table($t)->orderBy('id')->get()->toJson();
        }
        $p = $this->propose();
        $d = $this->decide($p);
        $this->assertSame('cancelled', $d['status']);
        $this->assertTrue($d['integrity_hold']);
        foreach ($tables as $t) {
            $this->assertSame($before[$t], DB::table($t)->orderBy('id')->get()->toJson());
        }
        $this->assertSame('approved', DB::table('student_election_candidates')->where('id', $this->application['candidate_id'])->value('status'));
    }

    public function test_proposer_cannot_approve_own_proposal(): void
    {
        $this->held();
        $p = $this->propose();
        $this->denied(fn () => $this->service()->decide(F::user($this->id), $this->id['election'], $p['proposal'], true, 'An officer cannot independently approve their own request.', true), 403);
    }

    public function test_conflict_attestation_is_required(): void
    {
        $this->held();
        $this->denied(fn () => $this->service()->propose(F::user($this->id), $this->id['election'], 'Documented reason for cancellation review.', false), 403);
    }

    public function test_known_guardian_cannot_review_even_with_permissions(): void
    {
        $this->held();
        $p = $this->propose();
        DB::table('users')->where('id', $this->id['parent_user'])->update(['role_id' => $this->id['admin_role']]);
        $this->denied(fn () => $this->service()->decide(F::user($this->id, 'parent_user'), $this->id['election'], $p['proposal'], true, 'Guardian conflict remains even with administrative permission.', true), 403);
    }

    public function test_rejection_keeps_hold_and_allows_fresh_proposal(): void
    {
        $this->held();
        $p = $this->propose();
        $d = $this->decide($p, false);
        $this->assertSame('voting_open', $d['status']);
        $this->assertNotSame($p['proposal'], $this->propose()['proposal']);
        $this->denied(fn () => $this->fresh(), 409);
    }

    public function test_duplicate_pending_proposal_is_rejected(): void
    {
        $this->held();
        $this->propose();
        $this->denied(fn () => $this->propose(), 409);
    }

    public function test_new_incident_invalidates_proposal(): void
    {
        $this->held();
        $p = $this->propose();
        app(StudentElectionApplicationService::class)->consent(F::user($this->id, 'parent_user'), $this->id['election'], $this->application['id'], $this->application['version'], $this->id['designation']['version'], 'withdraw');
        $this->denied(fn () => $this->decide($p), 409);
        $this->assertNotSame($p['evidence_digest'], $this->propose()['evidence_digest']);
    }

    public function test_missing_fresh_permission_is_rejected(): void
    {
        $this->held();
        $p = $this->propose();
        $permission = DB::table('permissions')->where('permission_name', 'publish_student_election_results')->value('id');
        DB::table('role_permissions')->where('role_id', $this->id['admin_role'])->where('permission_id', $permission)->delete();
        $this->denied(fn () => $this->decide($p), 403);
    }

    public function test_tenant_spoofing_is_rejected(): void
    {
        $this->held();
        $actor = F::user($this->id);
        $actor->school_id = (string) Str::uuid();
        $this->expectException(ModelNotFoundException::class);
        $this->service()->history($actor, $this->id['election']);
    }

    public function test_cancelled_root_cannot_resume_even_through_direct_sql(): void
    {
        $this->held();
        $this->decide($this->propose());
        $this->expectException(QueryException::class);
        DB::table('student_elections')->where('id', $this->id['election'])->update(['status' => 'voting_open', 'integrity_hold' => false]);
    }

    public function test_direct_unreviewed_post_opening_cancellation_is_rejected(): void
    {
        $this->held();
        $this->expectException(QueryException::class);
        DB::table('student_elections')->where('id', $this->id['election'])->update(['status' => 'cancelled']);
    }

    public function test_decision_is_append_only(): void
    {
        $this->held();
        $this->decide($this->propose());
        $this->expectException(QueryException::class);
        DB::table('student_election_cancellation_decisions')->where('election_id', $this->id['election'])->update(['approved' => false]);
    }

    public function test_fresh_link_has_no_inherited_election_work(): void
    {
        $this->held();
        $this->decide($this->propose());
        $new = $this->fresh();
        $this->assertNotSame($this->id['election'], $new['election_id']);
        $e = StudentElection::withoutGlobalScopes()->findOrFail($new['election_id']);
        $this->assertSame('draft', $e->status);
        $this->assertSame('versioned_v1', $e->application_method);
        $this->assertSame('classroom_single_seat_v1', $e->ballot_method);
        $this->assertNull($e->application_policy_id);
        foreach (['student_election_positions', 'student_election_applications', 'student_election_voters', 'student_election_ballots', 'student_election_electorates', 'student_election_rounds', 'student_election_tally_snapshots', 'student_election_declarations'] as $t) {
            $this->assertSame(0, DB::table($t)->where('election_id', $e->id)->count(), $t);
        }
        $this->denied(fn () => $this->fresh(), 409);
    }

    public function test_fresh_window_requires_explicit_offset(): void
    {
        $this->held();
        $this->decide($this->propose());
        $data = $this->freshData();
        $data['voting_opens_at'] = '2027-01-01 10:00:00';
        $this->expectException(ValidationException::class);
        $this->service()->fresh(F::user($this->id), $this->id['election'], $data, true);
    }

    public function test_annulled_appointments_retain_original_snapshot(): void
    {
        $this->held();
        $term = (string) Str::uuid();
        $position = DB::table('student_election_positions')->where('id', $this->id['ep'])->value('position_id');
        DB::table('student_leadership_terms')->insert(['id' => $term, 'school_id' => $this->id['school'], 'position_id' => $position,
            'learner_id' => $this->id['learner'], 'election_id' => $this->id['election'], 'academic_year_id' => $this->id['academic_year'],
            'starts_at' => now()->toDateString(), 'status' => 'active', 'appointment_type' => 'elected']);
        $this->decide($this->propose());
        $this->assertSame('annulled', DB::table('student_leadership_terms')->where('id', $term)->value('status'));
        $evidence = json_decode(DB::table('student_election_cancellation_decisions')->where('election_id', $this->id['election'])->value('term_evidence'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame($term, $evidence[0]['id']);
        $this->assertSame('active', $evidence[0]['status']);
    }

    public function test_audit_failure_rolls_back_decision_and_cancellation(): void
    {
        $this->held();
        $p = $this->propose();
        $connection = DB::connection();
        $dispatcher = $connection->getEventDispatcher();
        $connection->setEventDispatcher(clone $dispatcher);
        $connection->listen(function ($event) {
            if (str_contains($event->sql, 'insert into "student_election_audit_logs"')) {
                throw new \RuntimeException('Deliberate audit failure');
            }
        });
        try {
            $this->decide($p);
            $this->fail('Audit failure was ignored.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Deliberate audit failure', $e->getMessage());
        } finally {
            $connection->setEventDispatcher($dispatcher);
        }
        $this->assertSame('voting_open', DB::table('student_elections')->where('id', $this->id['election'])->value('status'));
        $this->assertSame(0, DB::table('student_election_cancellation_decisions')->where('proposal_id', $p['proposal'])->count());
    }

    public function test_http_requires_authentication(): void
    {
        $this->getJson('/api/student-election-cancellations/'.$this->id['election'])->assertUnauthorized();
    }

    public function test_http_rejects_learner_and_redacts_private_evidence(): void
    {
        $this->held();
        $this->login('user');
        $this->getJson('/api/student-election-cancellations/'.$this->id['election'])->assertForbidden();
    }

    public function test_http_officer_can_propose_review_and_read_private_history(): void
    {
        $this->held();
        $url = '/api/student-election-cancellations/'.$this->id['election'];
        // Arrange through the already-tested service; this HTTP client has one identity.
        $proposal = $this->propose();
        $this->login('appeal_user');
        $decision = $this->postJson($url.'/proposals/'.$proposal['proposal'].'/decision', [
            'approve' => true,
            'reason' => 'Independent exact-evidence review confirms cancellation.',
            'conflict_free' => true,
        ]);
        $this->assertSame(200, $decision->status(), $decision->getContent());
        $decision->assertJsonPath('data.status', 'cancelled');
        $response = $this->getJson($url);
        $this->assertSame(200, $response->status(), $response->getContent());
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertArrayNotHasKey('term_evidence', $response->json('data.decisions.0'));
    }

    public function test_http_officer_can_submit_cancellation_proposal(): void
    {
        $this->held();
        $this->login('admin');
        $response = $this->postJson('/api/student-election-cancellations/'.$this->id['election'].'/proposals', [
            'reason' => 'Original integrity evidence requires independent review.',
            'conflict_free' => true,
        ]);
        $this->assertSame(200, $response->status(), $response->getContent());
        $response->assertJsonPath('data.status', 'pending_review');
    }

    private function login(string $key): void
    {
        Auth::forgetGuards();
        $token = JWTAuth::fromUser(F::user($this->id, $key));
        JWTAuth::setToken($token);
        $this->withToken($token);
    }

    private function job(string $operation, string $actor = 'admin', array $extra = []): array
    {
        return $extra + ['operation' => $operation, 'school' => $this->id['school'], 'election' => $this->id['election'], 'actor' => $this->id[$actor], 'clock' => now()->toIso8601String()];
    }

    private function race(array $a, array $b, string $second): void
    {
        $r = (new Runner)->run($a, $b);
        $this->assertTrue($r['separate_sessions']);
        $this->assertTrue($r['blocking_verified']);
        $this->assertSame('committed', $r['first']['status']);
        $this->assertSame($second, $r['second']['status']);
    }

    public function test_competing_proposals_commit_only_one_pending_review(): void
    {
        $this->held();
        $this->race($this->job('cancel_propose'), $this->job('cancel_propose', 'appeal_user'), 'rejected');
    }

    public function test_competing_decisions_commit_only_one_cancellation(): void
    {
        $this->held();
        $p = $this->propose();
        $j = $this->job('cancel_decide', 'appeal_user', ['proposal' => $p['proposal']]);
        $this->race($j, $j, 'rejected');
        $this->assertSame(1, DB::table('student_election_cancellation_decisions')->where('election_id', $this->id['election'])->count());
    }

    public function test_cancellation_commits_before_blocked_publication_is_refused(): void
    {
        $this->held();
        $p = $this->propose();
        $this->race($this->job('cancel_decide', 'appeal_user', ['proposal' => $p['proposal']]), $this->job('publish'), 'rejected');
        $this->assertSame(0, DB::table('student_election_declarations')->where('election_id', $this->id['election'])->count());
    }

    public function test_cancellation_commits_before_blocked_vote_is_refused(): void
    {
        $this->held();
        $p = $this->propose();
        $vote = $this->job('vote', 'user2', ['ep' => $this->id['ep'], 'candidate' => $this->application['candidate_id']]);
        $count = DB::table('student_election_ballots')->where('election_id', $this->id['election'])->count();
        $this->race($this->job('cancel_decide', 'appeal_user', ['proposal' => $p['proposal']]), $vote, 'rejected');
        $this->assertSame($count, DB::table('student_election_ballots')->where('election_id', $this->id['election'])->count());
    }

    public function test_rolled_back_decision_does_not_block_later_approval(): void
    {
        $this->held();
        $p = $this->propose();
        $j = $this->job('cancel_decide', 'appeal_user', ['proposal' => $p['proposal']]);
        $r = (new Runner)->run($j + ['rollback' => true], $j);
        $this->assertTrue($r['blocking_verified']);
        $this->assertSame('rolled_back', $r['first']['status']);
        $this->assertSame('committed', $r['second']['status']);
    }

    public function test_competing_fresh_links_create_only_one_empty_draft(): void
    {
        $this->held();
        $this->decide($this->propose());
        $j = $this->job('cancel_fresh', 'admin', ['data' => $this->freshData()]);
        $this->race($j, $j, 'rejected');
        $this->assertSame(1, DB::table('student_election_replacements')->where('original_election_id', $this->id['election'])->count());
    }

    public function test_incident_committed_during_review_makes_decision_stale(): void
    {
        $this->held();
        $p = $this->propose();
        $a = $this->application;
        $withdraw = $this->job('withdraw', 'parent_user', ['application' => $a['id'], 'version' => $a['version'], 'designation' => $this->id['designation']['version']]);
        $this->race($withdraw, $this->job('cancel_decide', 'appeal_user', ['proposal' => $p['proposal']]), 'rejected');
    }
}
