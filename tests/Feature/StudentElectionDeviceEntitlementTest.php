<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\StudentElection;
use App\Services\StudentElection\StudentElectionStationService;
use App\Services\StudentElectionSubscription\StudentElectionCommercialAccess;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\Support\StudentElectionRoundFixture as R;
use Tests\Support\StudentElectionStationFixture as F;
use Tests\TestCase;

class StudentElectionDeviceEntitlementTest extends TestCase
{
    use DatabaseTransactions;

    private function stationFixture(): array
    {
        $this->travelTo(now()->startOfDay()->addHours(10));
        config(['testing.elections_period_starts_at' => CarbonImmutable::now('UTC')->subMonthsNoOverflow(12)->addMinutes(5)->toIso8601String()]);
        $id = F::create(true, false);

        return $id;
    }

    private function assertCommercialDenied(callable $fn): void
    {
        try {
            $fn();
            $this->fail('Expired device access was accepted.');
        } catch (HttpExceptionInterface $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
    }

    public function test_device_flow_requires_commercial_access_even_with_valid_credential(): void
    {
        $id = $this->stationFixture();
        DB::table('school_subscriptions')->where('school_id', $id['school'])->update(['status' => 'ACTIVE', 'expiry_date' => DB::raw('start_date'), 'grace_end_date' => null]);
        $this->assertCommercialDenied(fn () => app(StudentElectionStationService::class)->state($id['credential']));
        $this->assertSame(0, DB::table('student_election_ballots')->where('election_id', $id['election'])->count());
    }

    public function test_expired_annual_period_cannot_pair_another_device(): void
    {
        $id = $this->stationFixture();
        $end = CarbonImmutable::parse(DB::table('student_election_addon_periods')->where('school_id', $id['school'])->value('ends_at'))->setTimezone(now()->getTimezone());
        $this->travelTo($end->addSecond());
        $this->assertCommercialDenied(fn () => app(StudentElectionStationService::class)->pair(R::user($id), $id['election'], $id['station']));
    }

    public function test_expired_paid_device_can_submit_only_inside_its_recorded_window(): void
    {
        $id = $this->stationFixture();
        $end = CarbonImmutable::parse(DB::table('student_election_addon_periods')->where('school_id', $id['school'])->value('ends_at'))->setTimezone(now()->getTimezone());
        $this->travelTo($end->subSeconds(30));
        $state = F::ready($id);
        $this->travelTo($end->addSecond());
        $r = app(StudentElectionStationService::class)->submit($id['credential'], $state['session_id'], $state['definition_digest'], F::answers($state));
        $this->assertTrue($r['confirmed']);
    }

    public function test_device_http_enforces_main_subscription_even_with_valid_cookie(): void
    {
        $id = $this->stationFixture();
        DB::table('school_subscriptions')->where('school_id', $id['school'])->update(['status' => 'ACTIVE', 'expiry_date' => DB::raw('start_date'), 'grace_end_date' => null]);
        $this->withCredentials();
        $this->withHeaders(['Origin' => 'https://school.test', 'X-Election-Station' => '1']);
        $this->withUnencryptedCookie('shuleos_election_device', $id['credential']);
        $this->getJson('/api/election-station/state')->assertForbidden();
    }

    public function test_new_runoff_requires_current_entitlement(): void
    {
        $id = R::create(true, true);
        $end = CarbonImmutable::parse(DB::table('student_election_addon_periods')->where('school_id', $id['school'])->value('ends_at'))->setTimezone(now()->getTimezone());
        $this->travelTo($end->addSecond());
        $this->assertCommercialDenied(fn () => R::schedule($id));
    }

    public function test_scheduled_but_unopened_runoff_cannot_open_after_expiry(): void
    {
        $id = R::create(true, true);
        $round = R::schedule($id);
        $end = CarbonImmutable::parse(DB::table('student_election_addon_periods')->where('school_id', $id['school'])->value('ends_at'))->setTimezone(now()->getTimezone());
        $this->travelTo($end->addSecond());
        // Explicitly test the commercial gate before an unopened round could gain a receipt.
        $root = StudentElection::withoutGlobalScopes()->findOrFail($id['election']);
        $record = DB::table('student_election_rounds')->where('id', $round['id'])->firstOrFail();
        $this->assertCommercialDenied(fn () => app(StudentElectionCommercialAccess::class)->opened($root, $id['admin'], $record));
        $this->assertSame(0, DB::table('student_election_commercial_openings')->where('scope_id', $round['id'])->count());
    }

    public function test_opened_round_can_be_closed_and_tallied_after_expiry(): void
    {
        $id = R::create(true, true);
        $round = R::schedule($id);
        R::open($id, $round);
        $end = CarbonImmutable::parse(DB::table('student_election_addon_periods')->where('school_id', $id['school'])->value('ends_at'))->setTimezone(now()->getTimezone());
        $this->travelTo($end->addSecond());
        $r = R::finish($id, $round);
        $this->assertSame('tallied', DB::table('student_election_rounds')->where('id', $round['id'])->value('status'));
    }

    public function test_submission_rechecks_commercial_access_without_partial_ballots(): void
    {
        $id = $this->stationFixture();
        $state = F::ready($id);
        DB::table('school_subscriptions')->where('school_id', $id['school'])->update(['status' => 'ACTIVE', 'expiry_date' => DB::raw('start_date'), 'grace_end_date' => null]);
        $this->assertCommercialDenied(fn () => app(StudentElectionStationService::class)->submit($id['credential'], $state['session_id'], $state['definition_digest'], F::answers($state)));
        $this->assertSame(0, DB::table('student_election_ballots')->where('election_id', $id['election'])->count());
        $this->assertSame('approved', DB::table('student_election_station_sessions')->where('id', $state['session_id'])->value('status'));
    }
}
