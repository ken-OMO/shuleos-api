<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\StudentElection\StudentElectionApplicationService;
use App\Services\StudentElection\StudentElectionGuardianService;
use App\Services\StudentElection\StudentElectionResultService;
use App\Services\StudentElection\StudentElectionService;
use App\Services\StudentElection\StudentElectionVotingService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\StudentElectionApplicationFixture as F;
use Tests\TestCase;

class StudentElectionApplicationWorkflowTest extends TestCase
{
    use DatabaseTransactions;

    protected array $id = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->startOfSecond());
        $this->id = F::create();
        DB::table('student_elections')->where('id', $this->id['election'])->update(['status' => 'nominations_open']);
        $this->travel(2)->minutes();
    }

    protected function service(): StudentElectionApplicationService
    {
        return app(StudentElectionApplicationService::class);
    }

    protected function submit(?string $version = null, array $overrides = []): array
    {
        return $this->service()->submit(F::user($this->id, 'user'), $this->id['election'], $version, array_merge(F::data($this->id), $overrides));
    }

    protected function consent(array $a, string $decision = 'accept', string $key = 'parent_user'): array
    {
        return $this->service()->consent(F::user($this->id, $key), $this->id['election'], $a['id'], $a['version'], $this->id['designation']['version'], $decision);
    }

    protected function review(array $a, bool $approve = true, bool $appeal = false, string $key = 'admin'): array
    {
        return $this->service()->review(F::user($this->id, $key), $this->id['election'], $a['id'], $a['version'], $approve, 'The exact application was independently reviewed.', $appeal, true);
    }

    protected function denied(callable $action, int $status): void
    {
        try {
            $action();
            $this->fail('Unsafe application action succeeded.');
        } catch (HttpException $error) {
            $this->assertSame($status, $error->getStatusCode());
        }
    }

    public function test_exact_version_consent_and_review_approve_candidate(): void
    {
        $a = $this->submit();
        $this->assertSame('pending_consent', $a['status']);
        $this->assertSame('pending_review', $this->consent($a)['status']);
        $this->assertSame('approved', $this->review($a)['status']);
        $this->assertSame('approved', DB::table('student_election_candidates')->where('id', $a['candidate_id'])->value('status'));
        $this->assertSame(2, DB::table('student_election_application_events')->where('application_id', $a['id'])->count());
        $view = $this->service()->view(F::user($this->id, 'parent_user'), $this->id['election'], $a['id']);
        $this->assertSame($this->id['policy']['terms_digest'], $view['terms_digest']);
        $this->assertArrayNotHasKey('phone', $view);
        $this->assertArrayNotHasKey('review_comments', $view);
    }

    public function test_approval_requires_consent(): void
    {
        $a = $this->submit();
        $this->expectException(ValidationException::class);
        $this->review($a);
    }

    public function test_guardian_refusal_cannot_support_review(): void
    {
        $a = $this->submit();
        $this->consent($a, 'refuse');
        $this->expectException(ValidationException::class);
        $this->review($a);
    }

    public function test_staff_cannot_consent_for_a_guardian(): void
    {
        $a = $this->submit();
        $this->denied(fn () => $this->consent($a, 'accept', 'admin'), 403);
    }

    public function test_other_learner_cannot_read_the_application(): void
    {
        $a = $this->submit();
        $this->denied(fn () => $this->service()->view(F::user($this->id, 'user2'), $this->id['election'], $a['id']), 403);
    }

    public function test_material_edit_preserves_history_and_invalidates_approval(): void
    {
        $a = $this->submit();
        $this->consent($a);
        $this->review($a);
        $b = $this->submit($a['version'], ['slogan' => 'A revised pledge']);
        $this->assertSame($a['id'], $b['id']);
        $this->assertNotSame($a['version'], $b['version']);
        $this->assertSame('pending_consent', $b['status']);
        $this->assertSame(2, DB::table('student_election_application_versions')->where('application_id', $a['id'])->count());
        $this->denied(fn () => $this->consent($a), 409);
        $this->expectException(ValidationException::class);
        $this->review($b);
    }

    public function test_only_one_application_per_learner_across_the_election(): void
    {
        $a = $this->submit();
        $this->denied(fn () => $this->submit(), 409);
        $this->assertSame(1, DB::table('student_election_applications')->where('learner_id', $this->id['learner'])->count());
        $this->assertSame($a['version'], DB::table('student_election_applications')->where('id', $a['id'])->value('version_id'));
    }

    public function test_second_precision_consent_withdrawal_cannot_leave_acceptance_current(): void
    {
        $a = $this->submit();
        $this->consent($a);
        $this->consent($a, 'withdraw');
        $this->assertSame([1, 2], DB::table('student_election_application_events')->where('application_id', $a['id'])->orderBy('sequence')->pluck('sequence')->map(fn ($value) => (int) $value)->all());
        $this->expectException(ValidationException::class);
        $this->review($a);
    }

    public function test_review_requires_an_explicit_no_conflict_declaration(): void
    {
        $a = $this->submit();
        $this->consent($a);
        $this->denied(fn () => $this->service()->review(F::user($this->id), $this->id['election'], $a['id'], $a['version'], true, 'Attempted review without an explicit declaration.'), 403);
    }

    public function test_directly_approved_candidate_cannot_bypass_opening_gate(): void
    {
        $a = $this->submit();
        DB::table('student_election_candidates')->where('id', $a['candidate_id'])->update(['status' => 'approved']);
        $this->travel(60)->minutes();
        DB::table('student_elections')->where('id', $this->id['election'])->update(['status' => 'campaigning']);
        $this->expectException(ValidationException::class);
        app(StudentElectionService::class)->transition($this->id['school'], $this->id['election'], 'voting_open', $this->id['admin']);
    }

    public function test_stale_terms_are_rejected(): void
    {
        $this->denied(fn () => $this->submit(null, ['policy_version' => (string) Str::uuid()]), 409);
    }

    public function test_terms_acceptance_is_required(): void
    {
        $this->expectException(ValidationException::class);
        $this->submit(null, ['accept_terms' => false]);
    }

    public function test_raw_photo_paths_are_never_accepted(): void
    {
        $this->expectException(ValidationException::class);
        $this->submit(null, ['photo_path' => '../../private/guardian.jpg']);
    }

    public function test_application_deadline_is_checked_inside_transaction(): void
    {
        $this->travel(9)->minutes();
        $this->expectException(ValidationException::class);
        $this->submit();
    }

    public function test_old_review_endpoint_cannot_bypass_versioned_consent(): void
    {
        $a = $this->submit();
        $this->expectException(ValidationException::class);
        app(StudentElectionService::class)->review($this->id['school'], $a['candidate_id'], 'approved', $this->id['admin']);
    }

    public function test_wrong_officer_cannot_review_even_with_the_permission(): void
    {
        $a = $this->submit();
        $this->consent($a);
        $this->denied(fn () => $this->review($a, true, false, 'appeal_user'), 403);
    }

    public function test_primary_reviewer_cannot_decide_appeal(): void
    {
        $a = $this->submit();
        $this->consent($a);
        $this->review($a, false);
        $this->service()->appeal(F::user($this->id, 'user'), $this->id['election'], $a['id'], $a['version'], 'Please reconsider the documented rejection reasons.');
        $this->denied(fn () => $this->review($a, true, true), 403);
        $this->assertSame('approved', $this->review($a, true, true, 'appeal_user')['status']);
    }

    public function test_appeal_requires_a_learner_request(): void
    {
        $a = $this->submit();
        $this->consent($a);
        $this->review($a, false);
        $this->expectException(ValidationException::class);
        $this->review($a, true, true, 'appeal_user');
    }

    public function test_changed_designation_invalidates_approved_projection(): void
    {
        $a = $this->submit();
        $this->consent($a);
        $this->review($a);
        app(StudentElectionGuardianService::class)->revoke(F::user($this->id), $this->id['election'], $this->id['learner'], $this->id['designation']['version'], 'School revoked the guardian authority after verification.');
        $this->assertSame('pending_consent', DB::table('student_election_applications')->where('id', $a['id'])->value('status'));
        $this->assertSame('pending_review', DB::table('student_election_candidates')->where('id', $a['candidate_id'])->value('status'));
    }

    public function test_changed_guardian_link_prevents_review(): void
    {
        $a = $this->submit();
        $this->consent($a);
        DB::table('learner_parents')->where('id', $this->id['link'])->update(['active' => false]);
        $this->expectException(ValidationException::class);
        $this->review($a);
    }

    public function test_board_member_with_guardian_relationship_must_recuse(): void
    {
        $a = $this->submit();
        $this->consent($a);
        $p = (array) DB::table('parents')->where('id', $this->id['parent'])->first();
        $p['id'] = (string) Str::uuid();
        $p['user_id'] = $this->id['admin'];
        DB::table('parents')->insert($p);
        DB::table('learner_parents')->insert(['id' => (string) Str::uuid(), 'learner_id' => $this->id['learner'], 'parent_id' => $p['id'], 'active' => true, 'portal_enabled' => true]);
        $this->denied(fn () => $this->review($a), 403);
    }

    protected function open(array $a): void
    {
        $this->consent($a);
        $this->review($a);
        $this->travel(60)->minutes();
        DB::table('student_elections')->where('id', $this->id['election'])->update(['status' => 'campaigning']);
        app(StudentElectionService::class)->transition($this->id['school'], $this->id['election'], 'voting_open', $this->id['admin']);
    }

    public function test_post_opening_withdrawal_holds_voting_without_rewriting_ballots(): void
    {
        $a = $this->submit();
        $this->open($a);
        app(StudentElectionVotingService::class)->vote(F::user($this->id, 'user'), $this->id['election'], $this->id['ep'], $a['candidate_id']);
        $this->assertTrue($this->consent($a, 'withdraw')['integrity_hold']);
        $this->assertSame(1, DB::table('student_election_ballots')->where('election_id', $this->id['election'])->count());
        $this->assertSame('approved', DB::table('student_election_candidates')->where('id', $a['candidate_id'])->value('status'));
        $ballot = (array) DB::table('student_election_ballots')->where('election_id', $this->id['election'])->first();
        $ballot['id'] = (string) Str::uuid();
        $ballot['anonymous_ballot_token_hash'] = hash('sha256', random_bytes(32));
        try {
            DB::transaction(fn () => DB::table('student_election_ballots')->insert($ballot));
            $this->fail('Direct ballot insert bypassed the hold.');
        } catch (QueryException $error) {
            $this->assertStringContainsString('integrity hold', $error->getMessage());
        }
        $this->expectException(ValidationException::class);
        app(StudentElectionVotingService::class)->vote(F::user($this->id, 'user2'), $this->id['election'], $this->id['ep'], $a['candidate_id']);
    }

    public function test_hold_blocks_tally_even_with_tally_permission(): void
    {
        $a = $this->submit();
        $this->open($a);
        $this->consent($a, 'withdraw');
        $this->expectException(ValidationException::class);
        app(StudentElectionResultService::class)->tally($this->id['school'], $this->id['election'], $this->id['admin']);
    }

    public function test_rejected_or_withdrawn_application_is_not_approved_by_old_evidence(): void
    {
        $a = $this->submit();
        $this->consent($a);
        $this->review($a);
        $this->service()->withdraw(F::user($this->id, 'user'), $this->id['election'], $a['id'], $a['version'], 'I no longer wish to stand for this office.');
        $this->assertSame('withdrawn', DB::table('student_election_candidates')->where('id', $a['candidate_id'])->value('status'));
        $this->expectException(ValidationException::class);
        $this->review($a);
    }

    public function test_terms_and_version_evidence_are_immutable(): void
    {
        $a = $this->submit();
        foreach ([['student_election_application_policies', $this->id['policy']['version']], ['student_election_application_versions', $a['version']]] as [$table, $id]) {
            try {
                DB::transaction(fn () => DB::table($table)->where('id', $id)->delete());
                $this->fail('Immutable application evidence was removed.');
            } catch (QueryException $error) {
                $this->assertStringContainsString('append-only', $error->getMessage());
            }
        }
    }

    public function test_application_and_version_roll_back_on_audit_failure(): void
    {
        $c = DB::connection();
        $original = $c->getEventDispatcher();
        $d = clone $original;
        $c->setEventDispatcher($d);
        $d->listen(QueryExecuted::class, function (QueryExecuted $event): void {
            if (str_starts_with($event->sql, 'insert into "student_election_audit_logs"')) {
                throw new \RuntimeException('Injected application audit failure.');
            }
        });
        try {
            try {
                $this->submit();
                $this->fail('Audit failure was swallowed.');
            } catch (\RuntimeException $error) {
                $this->assertSame('Injected application audit failure.', $error->getMessage());
            }
        } finally {
            $c->setEventDispatcher($original);
        }
        $this->assertSame(0, DB::table('student_election_applications')->where('election_id', $this->id['election'])->count());
        $this->assertSame(0, DB::table('student_election_application_versions')->count());
    }
}
