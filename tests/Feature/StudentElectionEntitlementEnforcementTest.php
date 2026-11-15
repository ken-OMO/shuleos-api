<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\StudentElection;
use App\Services\ParentPortal\ParentPortalAccessService;
use App\Services\SchoolSetupReadinessService;
use App\Services\StudentElection\StudentElectionApplicationService;
use App\Services\StudentElection\StudentElectionCancellationService;
use App\Services\StudentElection\StudentElectionService;
use App\Services\StudentElection\StudentElectionVotingService;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\Support\StudentElectionApplicationFixture as F;
use Tests\Support\StudentElectionApplicationRaceFixture as Fixture;
use Tests\Support\StudentElectionApplicationRaceRunner as Runner;
use Tests\TestCase;

class StudentElectionEntitlementEnforcementTest extends TestCase
{
    private array $id = [];

    private array $application = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->startOfDay()->addHours(10));
        config(['jwt.secret' => str_repeat('election-entitlement-http-', 4)]);
        $this->mock(SchoolSetupReadinessService::class)->shouldReceive('isReady')->andReturn(true);
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

    private function periodEnd(): CarbonImmutable
    {
        return CarbonImmutable::parse(DB::table('student_election_addon_periods')->where('school_id', $this->id['school'])->value('ends_at'));
    }

    private function expirePeriod(): void
    {
        $this->travelTo($this->periodEnd()->addSecond());
    }

    private function lifecycle(): StudentElectionService
    {
        return app(StudentElectionService::class);
    }

    private function apps(): StudentElectionApplicationService
    {
        return app(StudentElectionApplicationService::class);
    }

    private function newElectionData(): array
    {
        return ['title' => 'New election requiring paid access', 'academic_year_id' => $this->id['academic_year'], 'voting_opens_at' => now()->addDays(2)->toIso8601String(), 'voting_closes_at' => now()->addDays(2)->addHour()->toIso8601String()];
    }

    private function submitApplication(): array
    {
        return $this->apps()->submit(F::user($this->id, 'user'), $this->id['election'], null, F::data($this->id));
    }

    private function assertCommercialDenied(callable $fn): void
    {
        try {
            $fn();
            $this->fail('Commercial enforcement was bypassed.');
        } catch (HttpExceptionInterface $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
    }

    private function openElection(): void
    {
        $a = $this->submitApplication();
        $this->application = $a;
        $this->apps()->consent(F::user($this->id, 'parent_user'), $this->id['election'], $a['id'], $a['version'], $this->id['designation']['version'], 'accept');
        $this->apps()->review(F::user($this->id), $this->id['election'], $a['id'], $a['version'], true, 'Exact-version independent approval with no conflict.', false, true);
        $end = $this->periodEnd();
        // Existing installations may use either PostgreSQL timestamp representation.
        // Preserve instants for timestamptz, and model-local wall time for timestamp.
        $columns = DB::select("SELECT attname, format_type(atttypid, atttypmod) AS kind FROM pg_attribute WHERE attrelid = 'student_elections'::regclass AND attname IN ('voting_opens_at', 'voting_closes_at') AND NOT attisdropped");
        $types = [];
        foreach ($columns as $column) {
            $types[$column->attname] = $column->kind;
        }
        $this->travelTo($end->subMinutes(5));
        $windowModel = StudentElection::withoutGlobalScopes()->findOrFail($this->id['election']);
        $modelTimezone = $windowModel->voting_opens_at->getTimezone()->getName();
        $encode = static function (CarbonImmutable $instant, string $kind) use ($modelTimezone): string {
            if (str_contains($kind, 'with time zone')) {
                return $instant->utc()->toIso8601String();
            }
            if (str_contains($kind, 'without time zone')) {
                return $instant->setTimezone($modelTimezone)->format('Y-m-d H:i:s');
            }
            throw new \RuntimeException('Unexpected Elections timestamp column type: '.$kind);
        };
        if (! isset($types['voting_opens_at'], $types['voting_closes_at'])) {
            throw new \RuntimeException('Election window columns are missing.');
        }
        DB::table('student_elections')->where('id', $this->id['election'])->update([
            'voting_opens_at' => $encode($end->subMinutes(10), $types['voting_opens_at']),
            'voting_closes_at' => $encode($end->addMinutes(20), $types['voting_closes_at']),
        ]);
        $root = StudentElection::withoutGlobalScopes()->findOrFail($this->id['election']);
        $this->assertTrue(
            $root->voting_opens_at->equalTo($end->subMinutes(10)) && $root->voting_closes_at->equalTo($end->addMinutes(20)),
            'Fixture timestamp encoding must preserve exact instants. '.json_encode([
                'expected_opens' => $end->subMinutes(10)->toIso8601String(),
                'expected_closes' => $end->addMinutes(20)->toIso8601String(),
                'actual_opens' => $root->voting_opens_at->toIso8601String(),
                'actual_closes' => $root->voting_closes_at->toIso8601String(),
                'model_timezone' => $modelTimezone,
                'php_timezone' => date_default_timezone_get(),
                'app_timezone' => config('app.timezone'),
            ], JSON_THROW_ON_ERROR)
        );
        $this->assertTrue(now()->gte($root->voting_opens_at) && now()->lt($root->voting_closes_at),
            'Synthetic voting window must contain the frozen application clock. '.json_encode([
                'clock' => now()->toIso8601String(), 'opens' => $root->voting_opens_at->toIso8601String(),
                'closes' => $root->voting_closes_at->toIso8601String(), 'php_timezone' => date_default_timezone_get(),
                'column_types' => $types,
            ], JSON_THROW_ON_ERROR));
        $this->lifecycle()->transition($this->id['school'], $this->id['election'], 'nominations_closed', $this->id['admin']);
        $this->lifecycle()->transition($this->id['school'], $this->id['election'], 'voting_open', $this->id['admin']);
    }

    private function httpLogin(string $key): void
    {
        Auth::forgetGuards();
        $token = JWTAuth::fromUser(F::user($this->id, $key));
        JWTAuth::setToken($token);
        $this->withToken($token);
    }

    public function test_new_election_creation_is_blocked_after_expiry(): void
    {
        $this->expirePeriod();
        $before = DB::table('student_elections')->where('school_id', $this->id['school'])->count();
        $this->assertCommercialDenied(fn () => $this->lifecycle()->create($this->id['school'], $this->newElectionData(), $this->id['admin']));
        $this->assertSame($before, DB::table('student_elections')->where('school_id', $this->id['school'])->count());
    }

    public function test_new_application_is_blocked_after_expiry(): void
    {
        $this->expirePeriod();
        $this->assertCommercialDenied(fn () => $this->submitApplication());
        $this->assertSame(0, DB::table('student_election_applications')->where('election_id', $this->id['election'])->count());
    }

    public function test_paid_opening_records_one_immutable_receipt(): void
    {
        $this->openElection();
        $this->assertSame(1, DB::table('student_election_commercial_openings')->where('election_id', $this->id['election'])->count());
        $this->expectException(QueryException::class);
        DB::table('student_election_commercial_openings')->where('election_id', $this->id['election'])->delete();
    }

    public function test_opening_audit_failure_rolls_back_receipt_and_electorate(): void
    {
        $connection = DB::connection();
        $dispatcher = $connection->getEventDispatcher();
        $connection->setEventDispatcher(clone $dispatcher);
        $connection->listen(function ($event) {
            if (str_contains($event->sql, 'insert into "student_election_audit_logs"') && in_array('commercial_opening_recorded', $event->bindings, true)) {
                throw new \RuntimeException('Deliberate opening audit failure');
            }
        });
        try {
            $this->openElection();
            $this->fail('Opening audit failure was ignored.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Deliberate opening audit failure', $e->getMessage());
        } finally {
            $connection->setEventDispatcher($dispatcher);
        }
        $this->assertSame(0, DB::table('student_election_commercial_openings')->where('election_id', $this->id['election'])->count());
        $this->assertSame(0, DB::table('student_election_electorates')->where('election_id', $this->id['election'])->count());
        $this->assertSame('nominations_closed', DB::table('student_elections')->where('id', $this->id['election'])->value('status'));
    }

    public function test_expired_period_can_finish_a_previously_paid_vote_in_its_captured_window(): void
    {
        $this->openElection();
        $this->expirePeriod();
        $r = app(StudentElectionVotingService::class)->vote(F::user($this->id, 'user'), $this->id['election'], $this->id['ep'], $this->application['candidate_id']);
        $this->assertTrue($r['confirmed']);
        $this->assertSame(1, DB::table('student_election_ballots')->where('election_id', $this->id['election'])->count());
    }

    public function test_window_extension_cannot_extend_expired_paid_access(): void
    {
        $this->openElection();
        $this->expirePeriod();
        DB::table('student_elections')->where('id', $this->id['election'])->update(['voting_closes_at' => now()->addDays(3)]);
        $this->assertCommercialDenied(fn () => app(StudentElectionVotingService::class)->vote(F::user($this->id, 'user'), $this->id['election'], $this->id['ep'], $this->application['candidate_id']));
    }

    public function test_closed_window_refuses_continuation(): void
    {
        $this->openElection();
        $this->travelTo($this->periodEnd()->addMinutes(20));
        $this->assertCommercialDenied(fn () => app(StudentElectionVotingService::class)->vote(F::user($this->id, 'user'), $this->id['election'], $this->id['ep'], $this->application['candidate_id']));
    }

    public function test_expired_original_can_close_after_its_voting_window(): void
    {
        $this->openElection();
        $this->travelTo($this->periodEnd()->addHour());
        $e = $this->lifecycle()->transition($this->id['school'], $this->id['election'], 'voting_closed', $this->id['admin']);
        $this->assertSame('voting_closed', $e->status);
        $this->lifecycle()->tally($this->id['school'], $this->id['election'], $this->id['admin']);
        $this->assertSame('tallied', DB::table('student_elections')->where('id', $e->id)->value('status'));
    }

    public function test_main_subscription_restriction_still_blocks_live_voting(): void
    {
        $this->openElection();
        $this->expirePeriod();
        DB::table('school_subscriptions')->where('school_id', $this->id['school'])->update(['status' => 'ACTIVE', 'expiry_date' => DB::raw('start_date'), 'grace_end_date' => null]);
        $this->assertCommercialDenied(fn () => app(StudentElectionVotingService::class)->vote(F::user($this->id, 'user'), $this->id['election'], $this->id['ep'], $this->application['candidate_id']));
    }

    public function test_post_opening_withdrawal_and_independent_cancellation_remain_available_after_expiry(): void
    {
        $this->openElection();
        $this->expirePeriod();
        $a = $this->application;
        $r = $this->apps()->consent(F::user($this->id, 'parent_user'), $this->id['election'], $a['id'], $a['version'], $this->id['designation']['version'], 'withdraw');
        $this->assertTrue($r['integrity_hold']);
        $c = app(StudentElectionCancellationService::class);
        $p = $c->propose(F::user($this->id), $this->id['election'], 'Recorded integrity incident requires reviewed cancellation.', true);
        $d = $c->decide(F::user($this->id, 'appeal_user'), $this->id['election'], $p['proposal'], true, 'Different officer independently reviewed the cancellation evidence.', true);
        $this->assertSame('cancelled', $d['status']);
        $this->assertCommercialDenied(fn () => $c->fresh(F::user($this->id), $this->id['election'], $this->newElectionData(), true));
    }

    public function test_general_portal_switches_do_not_block_scoped_application_and_consent(): void
    {
        DB::table('school_settings')->where('school_id', $this->id['school'])->update(['learner_portal_enabled' => false, 'parent_portal_enabled' => false]);
        $a = $this->submitApplication();
        $r = $this->apps()->consent(F::user($this->id, 'parent_user'), $this->id['election'], $a['id'], $a['version'], $this->id['designation']['version'], 'accept');
        $this->assertSame('pending_review', $r['status']);
        $this->assertFalse((bool) DB::table('school_settings')->where('school_id', $this->id['school'])->value('parent_portal_enabled'));
        $this->expectException(AuthorizationException::class);
        app(ParentPortalAccessService::class)->parent(F::user($this->id, 'parent_user'));
    }

    public function test_personal_learner_revocation_is_not_bypassed(): void
    {
        DB::table('learners')->where('id', $this->id['learner'])->update(['portal_enabled' => false]);
        $this->expectException(AuthorizationException::class);
        $this->submitApplication();
    }

    public function test_expired_http_application_cannot_mutate_data(): void
    {
        $this->expirePeriod();
        $this->httpLogin('user');
        $this->postJson('/api/student-election-applications/'.$this->id['election'], F::data($this->id))->assertForbidden();
    }

    public function test_scoped_http_works_without_enabling_wider_portals(): void
    {
        DB::table('school_settings')->where('school_id', $this->id['school'])->update(['learner_portal_enabled' => false]);
        $this->httpLogin('user');
        $this->postJson('/api/student-election-applications/'.$this->id['election'], F::data($this->id))->assertOk()->assertJsonPath('data.status', 'pending_consent');
    }

    public function test_private_historical_application_remains_readable_after_expiry(): void
    {
        $a = $this->submitApplication();
        $this->expirePeriod();
        DB::table('schools')->where('id', $this->id['school'])->update(['lifecycle_state' => 'read_only']);
        $view = $this->apps()->view(F::user($this->id, 'user'), $this->id['election'], $a['id']);
        $this->assertSame($a['version'], $view['version']);
    }

    public function test_expired_preopening_configuration_is_refused(): void
    {
        $this->expirePeriod();
        $this->assertCommercialDenied(fn () => $this->lifecycle()->generateVoters($this->id['school'], $this->id['election']));
    }

    private function job(string $operation, array $extra = []): array
    {
        return $extra + ['operation' => $operation, 'school' => $this->id['school'], 'election' => $this->id['election'], 'actor' => $this->id['admin'], 'clock' => now()->toIso8601String()];
    }

    public function test_expiry_is_rechecked_after_actual_main_subscription_lock_wait(): void
    {
        $this->travelTo($this->periodEnd()->subSecond());
        $first = $this->job('commercial_hold_main');
        $second = $this->job('commercial_new_election', ['data' => $this->newElectionData(), 'after_commercial_wait_clock' => $this->periodEnd()->addSecond()->toIso8601String()]);
        $r = (new Runner)->run($first, $second);
        $this->assertTrue($r['separate_sessions']);
        $this->assertTrue($r['blocking_verified']);
        $this->assertSame('committed', $r['first']['status']);
        $this->assertSame('rejected', $r['second']['status']);
    }

    public function test_live_continuation_survives_actual_lock_wait_only_with_receipt(): void
    {
        $this->openElection();
        $this->travelTo($this->periodEnd()->subSecond());
        $r = (new Runner)->run($this->job('commercial_hold_main'), $this->job('commercial_live', ['after_commercial_wait_clock' => $this->periodEnd()->addSecond()->toIso8601String()]));
        $this->assertTrue($r['blocking_verified']);
        $this->assertSame('committed', $r['second']['status']);
    }

    public function test_main_expiry_committed_during_lock_wait_refuses_new_work(): void
    {
        $r = (new Runner)->run($this->job('commercial_main_expire'), $this->job('commercial_new_election', ['data' => $this->newElectionData()]));
        $this->assertTrue($r['blocking_verified']);
        $this->assertSame('rejected', $r['second']['status']);
    }
}
