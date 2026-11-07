<?php

declare(strict_types=1);

namespace Tests\Feature\TransportSubscription;

use App\Models\User;
use App\Services\Transport\TransportAccessService;
use App\Services\Transport\TransportAttendanceService;
use App\Services\Transport\TransportAuditService;
use App\Services\Transport\TransportExceptionService;
use App\Services\Transport\TransportFoundationService;
use App\Services\Transport\TransportHandoverAuthorizationService;
use App\Services\Transport\TransportReportingSchedulerService;
use App\Services\Transport\TransportTripService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\Database\RoleBuilder;
use Tests\Support\Database\SchoolBuilder;
use Tests\Support\Database\UserBuilder;
use Tests\Support\TransportHttpTestCase;
use Tests\Support\TransportSubscriptionFixture;

class TransportEntitlementEnforcementTest extends TransportHttpTestCase
{
    public function test_transport_permission_without_entitlement_cannot_write_in_service_or_http(): void
    {
        $f = $this->bare();
        $before = $this->httpSnapshot($f);
        $this->denied(fn () => DB::transaction(fn () => app(TransportAccessService::class)->actor($f['actor'], true)), 403);
        $this->httpAs($f['actor']);
        $this->postJson('/api/transport/vehicles', $this->vehicleInput())->assertForbidden();
        $this->getJson('/api/transport/vehicles')->assertOk();
        $this->assertSame($before, $this->httpSnapshot($f));
    }

    public function test_future_period_is_not_early_access_and_start_is_inclusive(): void
    {
        $f = $this->bare();
        TransportSubscriptionFixture::activate($f['school']->id, '2026-11-11T02:00:00Z', '2026-11-10T02:00:00Z');
        $this->denied(fn () => $this->createVehicle($f), 403);
        CarbonImmutable::setTestNow('2026-11-10T02:00:00Z');
        $this->assertNotEmpty($this->createVehicle($f)['id']);
    }

    public function test_any_existing_main_package_can_use_transport_without_an_enterprise_upgrade(): void
    {
        foreach (['Basic', 'Premium', 'Enterprise'] as $name) {
            $f = $this->bare();
            TransportSubscriptionFixture::activate($f['school']->id);
            $base = DB::table('school_subscriptions')->where('school_id', $f['school']->id)->first();
            DB::table('subscription_packages')->where('id', $base->package_id)->update(['package_name' => $name.'-'.Str::random(12)]);
            $before = DB::table('school_subscriptions')->where('school_id', $f['school']->id)->get()->toJson();
            $this->assertNotEmpty($this->createVehicle($f)['id']);
            $this->assertSame($before, DB::table('school_subscriptions')->where('school_id', $f['school']->id)->get()->toJson());
        }
    }

    public function test_entitlement_does_not_grant_permissions_and_missing_main_package_denies_normal_writes(): void
    {
        $f = $this->bare();
        TransportSubscriptionFixture::activate($f['school']->id);
        $this->removePermission($f['actor'], 'manage_transport');
        $this->denied(fn () => $this->createVehicle($f), 403);
        $this->grant($f['actor'], 'manage_transport');
        DB::table('school_subscriptions')->where('school_id', $f['school']->id)->delete();
        $this->denied(fn () => $this->createVehicle($f), 403);
    }

    public function test_base_expiry_blocks_configuration_but_keeps_concrete_custody_closure(): void
    {
        $f = $this->journey();
        $this->recordEvent($f, 'pickup', $f['guardianGrant']['id']);
        DB::table('school_subscriptions')->where('school_id', $f['school']->id)
            ->update(['expiry_date' => '2026-11-08', 'status' => 'LOCKED']);
        $this->denied(fn () => $this->createVehicle($f), 403);
        $this->assertSame('at_school', $this->recordEvent($f, 'school_arrival', $f['schoolGrant']['id'], $f['receiver'])['state']);
    }

    public function test_read_only_can_close_custody_but_suspension_cannot_bypass_identity_security(): void
    {
        $f = $this->journey();
        $this->recordEvent($f, 'pickup', $f['guardianGrant']['id']);
        DB::table('schools')->where('id', $f['school']->id)->update(['lifecycle_state' => 'read_only']);
        $this->denied(fn () => $this->createVehicle($f), 403);
        $this->assertSame('at_school', $this->recordEvent($f, 'school_arrival', $f['schoolGrant']['id'], $f['receiver'])['state']);
        $g = $this->journey();
        $this->recordEvent($g, 'pickup', $g['guardianGrant']['id']);
        $input = $this->input($g, 'school_arrival', $g['schoolGrant']['id']);
        DB::table('schools')->where('id', $g['school']->id)->update(['lifecycle_state' => 'locked', 'active' => false]);
        $this->httpAs($g['receiver']);
        $this->postJson('/api/transport/rosters/'.$g['roster'].'/events', $input)
            ->assertStatus(403);
    }

    public function test_exact_expiry_blocks_capture_configuration_and_new_boarding_even_for_existing_roster(): void
    {
        $f = $this->expiringJourney();
        $before = $this->httpSnapshot($f);
        $this->expire();
        $this->denied(fn () => $this->createVehicle($f), 403);
        $this->denied(fn () => app(TransportTripService::class)->capture($f['actor'], ['assignment_id' => $f['assignment']['id']]), 403);
        $this->denied(fn () => $this->recordEvent($f, 'pickup', $f['guardianGrant']['id']), 403);
        $this->denied(fn () => $this->recordEvent($f, 'school_arrival', $f['schoolGrant']['id'], $f['receiver']), 403);
        $this->assertSame($before, $this->httpSnapshot($f));
    }

    public function test_outbound_departure_after_expiry_is_not_a_continuation(): void
    {
        $f = $this->expiringJourney('from_school');
        $this->expire();
        $this->denied(fn () => $this->recordEvent($f, 'school_departure', $f['schoolGrant']['id']), 403);
        $this->assertSame('expected', $this->events()->show($f['actor'], $f['roster'])['state']);
    }

    public function test_http_safe_arrival_after_expiry_preserves_parties_and_appends_continuation_audit(): void
    {
        $f = $this->expiringJourney();
        $this->recordEvent($f, 'pickup', $f['guardianGrant']['id']);
        $this->expire();
        $this->httpAs($f['receiver']);
        $this->postJson('/api/transport/rosters/'.$f['roster'].'/events', $this->input($f, 'school_arrival', $f['schoolGrant']['id']))
            ->assertCreated()->assertJsonPath('data.state', 'at_school');
        $event = DB::table('transport_journey_events')->where('roster_id', $f['roster'])->orderByDesc('sequence')->first();
        $audit = DB::table('audit_logs')->where('record_id', $event->id)->first();
        $values = json_decode($audit->new_values, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('continuation', $values['entitlement_access']['mode']);
        $this->assertSame('custody_handover', $values['entitlement_access']['scope']);
        $this->assertNotEmpty($event->from_person);
        $this->assertNotEmpty($event->to_person);
        $this->assertSame(2, DB::table('transport_journey_events')->where('roster_id', $f['roster'])->count());
    }

    public function test_failed_delivery_follow_up_and_later_resolution_keep_original_custody_evidence(): void
    {
        $f = $this->expiringJourney('from_school');
        $departed = $this->recordEvent($f, 'school_departure', $f['schoolGrant']['id']);
        $this->expire();
        $failed = $this->events()->record($f['actor'], $f['roster'], [
            'expected_version' => $departed['version'], 'boundary' => 'destination_dropoff',
            'outcome' => 'failed', 'crew_user_id' => $f['actor']->id,
            'occurred_at' => CarbonImmutable::now('UTC')->toIso8601String(),
            'receipt_method' => 'none', 'failure_reason' => 'person_unavailable',
            'follow_up' => 'Remain with learner and arrange verified recipient.',
        ]);
        $this->assertSame('on_board', $failed['state']);
        $failure = $failed['events'][1]['id'];
        $exceptions = app(TransportExceptionService::class);
        $current = $exceptions->show($f['actor'], $failure);
        $follow = $exceptions->append($f['actor'], $failure, [
            'expected_version' => $current['version'], 'expected_journey_version' => $current['journey_version'],
            'action' => 'follow_up', 'note' => 'Contacted recipient; actual verified handover still pending.',
        ]);
        $this->assertSame('open', $follow['status']);
        $delivered = $this->recordEvent($f, 'destination_dropoff', $f['guardianGrant']['id'], null, 'witnessed_verbal');
        $current = $exceptions->show($f['actor'], $failure);
        $resolved = $exceptions->append($f['actor'], $failure, [
            'expected_version' => $current['version'], 'expected_journey_version' => $current['journey_version'],
            'action' => 'resolve', 'note' => 'Confirmed actual delivery using the later immutable handover event.',
            'resolution_event_id' => $delivered['events'][2]['id'],
        ]);
        $this->assertSame('resolved', $resolved['status']);
        $this->assertSame('failed', DB::table('transport_journey_events')->where('id', $failure)->value('outcome'));
    }

    public function test_replacement_recipient_scope_is_only_current_onboard_trip_and_date(): void
    {
        $f = $this->expiringJourney();
        $this->recordEvent($f, 'pickup', $f['guardianGrant']['id']);
        $this->expire();
        $service = app(TransportHandoverAuthorizationService::class);
        $data = ['assignment_id' => $f['assignment']['id'], 'kind' => 'school_receiver',
            'staff_user_id' => $f['receiver']->id, 'valid_from' => '2026-11-09', 'valid_to' => '2026-11-09',
            'reason' => 'Replacement receiver for the learner already onboard.'];
        $grant = $service->create($f['actor'], $data);
        $this->denied(fn () => $service->create($f['actor'], [...$data, 'valid_to' => '2026-11-10']), 403);
        $this->denied(fn () => $service->create($f['actor'], [...$data, 'kind' => 'school_releaser']), 403);
        $service->revoke($f['actor'], $grant['id'], ['expected_version' => $grant['version'], 'reason' => 'Use the original verified school receiver instead.']);
        $this->recordEvent($f, 'school_arrival', $f['schoolGrant']['id'], $f['receiver']);
        $this->denied(fn () => $service->create($f['actor'], $data), 403);
    }

    public function test_attendance_and_trip_completion_can_close_existing_capture_after_expiry(): void
    {
        $f = $this->expiringJourney();
        $this->expire();
        $service = app(TransportAttendanceService::class);
        $current = $this->events()->show($f['actor'], $f['roster']);
        $service->recordOutcome($f['actor'], $f['roster'], ['expected_version' => $current['version'],
            'outcome' => 'cancelled', 'reason' => 'No pickup occurred; recorded as a non-travel cancellation.']);
        CarbonImmutable::setTestNow('2026-11-09T06:00:00Z');
        $before = $service->showTrip($f['actor'], $this->tripId($f));
        $done = $service->completeTrip($f['actor'], $this->tripId($f), ['expected_version' => $before['version'],
            'reason' => 'Reconciled captured roster without inventing any delivery.']);
        $this->assertSame('completed', $done['completion_history'][0]['status']);
        $this->assertSame(0, DB::table('transport_journey_events')->where('roster_id', $f['roster'])->count());
    }

    public function test_preexisting_service_report_submission_and_independent_review_survive_expiry(): void
    {
        $this->transportAddonEndsAt = '2026-11-09T04:31:00Z';
        $f = $this->dailyFixture();
        $this->recordEvent($f, 'pickup', $f['guardianGrant']['id']);
        $reviewer = $this->reviewer($f);
        $this->grant($f['actor'], 'review_transport_daily_reports');
        CarbonImmutable::setTestNow('2026-11-09T06:00:00Z');
        $result = $this->submitDaily($f);
        $saved = $result['submissions'][0];
        $this->assertSame(1, $saved['snapshot']['totals']['on_board']);
        $this->denied(fn () => $this->daily()->review($f['actor'], $saved['id'], [
            'expected_version' => $saved['review_version'], 'decision' => 'accepted', 'note' => 'Own report review must remain forbidden.']), 403);
        $reviewed = $this->daily()->review($reviewer, $saved['id'], [
            'expected_version' => $saved['review_version'], 'decision' => 'accepted',
            'note' => 'Independent review preserves the reported unresolved custody.']);
        $this->assertSame($saved['snapshot'], $reviewed['submissions'][0]['snapshot']);
        $this->assertSame('accepted', $reviewed['state']);
    }

    public function test_scheduler_and_materialization_do_not_bypass_expiry(): void
    {
        $f = $this->expiringJourney();
        $policy = $this->policy($f);
        $before = $this->httpSnapshot($f);
        $this->expire();
        $this->denied(fn () => $this->reporting()->materialize($f['actor'], $policy['id']), 403);
        config(['transport_reporting.scheduler_enabled' => true,
            'transport_reporting.scheduler_actors' => [$f['school']->id => $f['actor']->id]]);
        $result = app(TransportReportingSchedulerService::class)->run();
        $this->assertSame(1, $result['failed']);
        $this->assertSame(0, $result['created']);
        $this->assertSame('access_unavailable', $result['schools'][0]['status']);
        $this->assertSame($before, $this->httpSnapshot($f));
    }

    public function test_continuation_does_not_extend_online_window_or_admit_stale_versions(): void
    {
        $f = $this->expiringJourney();
        $this->recordEvent($f, 'pickup', $f['guardianGrant']['id']);
        $this->expire();
        $input = $this->input($f, 'school_arrival', $f['schoolGrant']['id']);
        $this->denied(fn () => $this->events()->record($f['receiver'], $f['roster'], [...$input,
            'expected_version' => str_repeat('0', 64)]), 409);
        $this->denied(fn () => $this->events()->record($f['receiver'], $f['roster'], [...$input,
            'occurred_at' => '2026-11-09T04:00:00Z']), 422);
        CarbonImmutable::setTestNow('2026-11-10T04:31:00Z');
        $this->denied(fn () => $this->recordEvent($f, 'school_arrival', $f['schoolGrant']['id'], $f['receiver']), 422);
        $this->assertSame('on_board', $this->events()->show($f['actor'], $f['roster'])['state']);
    }

    public function test_tenant_and_concrete_crew_permission_checks_still_apply_to_continuation(): void
    {
        $a = $this->expiringJourney();
        $this->recordEvent($a, 'pickup', $a['guardianGrant']['id']);
        CarbonImmutable::setTestNow('2026-11-09T02:00:00Z');
        $b = $this->expiringJourney();
        $this->recordEvent($b, 'pickup', $b['guardianGrant']['id']);
        $this->expire();
        foreach ([[$a, $b], [$b, $a]] as [$own, $other]) {
            $this->denied(fn () => $this->events()->record($own['actor'], $other['roster'],
                $this->input($other, 'school_arrival', $other['schoolGrant']['id'])), 404);
        }
        $this->removePermission($a['receiver'], 'record_transport_journeys');
        $this->denied(fn () => $this->recordEvent($a, 'school_arrival', $a['schoolGrant']['id'], $a['receiver']), 403);
    }

    public function test_audit_failure_rolls_back_continuation_handover_and_custody(): void
    {
        $f = $this->expiringJourney();
        $this->recordEvent($f, 'pickup', $f['guardianGrant']['id']);
        $this->expire();
        $before = $this->httpSnapshot($f);
        $this->mock(TransportAuditService::class)->shouldReceive('record')->once()
            ->andThrow(new \RuntimeException('Strict continuation audit failure.'));
        try {
            $this->recordEvent($f, 'school_arrival', $f['schoolGrant']['id'], $f['receiver']);
            $this->fail('Audit failure must roll back the handover.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Strict continuation audit failure.', $e->getMessage());
        }
        $this->assertSame($before, $this->httpSnapshot($f));
        $this->assertSame('on_board', $this->events()->show($f['actor'], $f['roster'])['state']);
    }

    public function test_revoked_recipient_cannot_receive_after_expiry_and_crew_retains_custody(): void
    {
        $f = $this->expiringJourney();
        $this->recordEvent($f, 'pickup', $f['guardianGrant']['id']);
        $this->expire();
        app(TransportHandoverAuthorizationService::class)->revoke($f['actor'], $f['schoolGrant']['id'], [
            'expected_version' => $f['schoolGrant']['version'], 'reason' => 'Receiver is unavailable; do not release this learner.',
        ]);
        $this->denied(fn () => $this->recordEvent($f, 'school_arrival', $f['schoolGrant']['id'], $f['receiver']), 422);
        $this->assertSame('on_board', $this->events()->show($f['actor'], $f['roster'])['state']);
    }

    public function test_onboard_completion_stays_incomplete_after_expiry(): void
    {
        $f = $this->expiringJourney();
        $this->recordEvent($f, 'pickup', $f['guardianGrant']['id']);
        CarbonImmutable::setTestNow('2026-11-09T06:00:00Z');
        $service = app(TransportAttendanceService::class);
        $before = $service->showTrip($f['actor'], $this->tripId($f));
        $result = $service->completeTrip($f['actor'], $this->tripId($f), [
            'expected_version' => $before['version'], 'reason' => 'Learner remains onboard; this run cannot be completed.',
        ]);
        $this->assertSame('incomplete', $result['completion_history'][0]['status']);
        $this->assertSame('on_board', $this->events()->show($f['actor'], $f['roster'])['state']);
    }

    public function test_explicit_main_grace_date_is_honored_without_automatic_addon_grace(): void
    {
        $f = $this->bare();
        TransportSubscriptionFixture::activate($f['school']->id);
        DB::table('school_subscriptions')->where('school_id', $f['school']->id)->update([
            'status' => 'GRACE_PERIOD', 'expiry_date' => '2026-11-08', 'grace_end_date' => '2026-11-09',
        ]);
        $this->assertNotEmpty($this->createVehicle($f)['id']);
        CarbonImmutable::setTestNow('2026-11-09T21:00:00Z');
        $this->denied(fn () => $this->createVehicle($f), 403);
    }

    public function test_unassigned_user_cannot_deliver_using_permission_alone_after_expiry(): void
    {
        $f = $this->expiringJourney();
        $this->recordEvent($f, 'pickup', $f['guardianGrant']['id']);
        $row = UserBuilder::create($f['school'], RoleBuilder::create());
        $stranger = User::withoutGlobalScopes()->findOrFail($row->id);
        $this->grant($stranger, 'record_transport_journeys');
        $this->expire();
        $this->denied(fn () => $this->recordEvent($f, 'school_arrival', $f['schoolGrant']['id'], $stranger), 403);
        $this->assertSame('on_board', $this->events()->show($f['actor'], $f['roster'])['state']);
    }

    public function test_capture_and_boarding_can_span_adjacent_renewals_without_stranding_custody(): void
    {
        $f = $this->expiringJourney();
        $original = DB::table('transport_addon_periods')->where('school_id', $f['school']->id)->first();
        $renewal = (array) $original;
        $renewal['id'] = (string) Str::uuid();
        $renewal['request_id'] = (string) Str::uuid();
        $renewal['previous_id'] = $original->id;
        $renewal['starts_at'] = '2026-11-09T04:31:00+00:00';
        $renewal['ends_at'] = '2026-11-09T04:33:00+00:00';
        $renewal['evidence_reference'] = 'TEST-ADJACENT-'.Str::upper(Str::random(12));
        $renewal['request_fingerprint'] = hash('sha256', $renewal['id']);
        DB::table('transport_addon_periods')->insert($renewal);
        CarbonImmutable::setTestNow('2026-11-09T04:31:00Z');
        $this->recordEvent($f, 'pickup', $f['guardianGrant']['id']);
        CarbonImmutable::setTestNow('2026-11-09T04:33:00Z');
        $this->assertSame('at_school', $this->recordEvent($f, 'school_arrival', $f['schoolGrant']['id'], $f['receiver'])['state']);
        $this->assertSame(2, DB::table('transport_addon_periods')->where('school_id', $f['school']->id)->count());
    }

    public function test_deactivated_actor_cannot_use_continuation_with_an_existing_token(): void
    {
        $f = $this->expiringJourney();
        $this->recordEvent($f, 'pickup', $f['guardianGrant']['id']);
        $this->expire();
        $input = $this->input($f, 'school_arrival', $f['schoolGrant']['id']);
        $this->httpAs($f['receiver']);
        DB::table('users')->where('id', $f['receiver']->id)->update(['active' => false]);
        $this->postJson('/api/transport/rosters/'.$f['roster'].'/events', $input)->assertUnauthorized();
        $last = DB::table('transport_journey_events')->where('roster_id', $f['roster'])->orderByDesc('sequence')->first();
        $this->assertSame('on_board', $last->state_after);
    }

    private function expiringJourney(string $direction = 'to_school'): array
    {
        $this->transportAddonEndsAt = '2026-11-09T04:31:00Z';

        return $this->journey($direction);
    }

    private function expire(): void
    {
        CarbonImmutable::setTestNow('2026-11-09T04:31:00Z');
    }

    private function bare(): array
    {
        $school = SchoolBuilder::create(['operating_mode' => 'day', 'lifecycle_state' => 'active', 'timezone' => 'Africa/Nairobi']);
        $role = RoleBuilder::create();
        $row = UserBuilder::create($school, $role);
        $actor = User::withoutGlobalScopes()->findOrFail($row->id);
        $this->grant($actor, 'manage_transport');
        $this->grant($actor, 'view_transport_operations');

        return compact('school', 'actor');
    }

    private function vehicleInput(): array
    {
        return ['registration_number' => 'ADDON-'.Str::random(10), 'ownership_type' => 'school_owned', 'learner_capacity' => 20];
    }

    private function createVehicle(array $f): array
    {
        return app(TransportFoundationService::class)->create($f['actor'], 'vehicle', $this->vehicleInput());
    }
}
