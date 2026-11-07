<?php

declare(strict_types=1);

namespace Tests\Feature\Transport;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\Support\TransportHttpTestCase;

class TransportJourneyHttpTest extends TransportHttpTestCase
{
    public function test_capture_is_idempotent_and_assigned_crew_can_read_without_leadership_view(): void
    {
        $f = $this->fixture();
        $this->removePermission($f['actor'], 'view_transport_operations');
        $this->httpAs($f['actor']);
        $trip = $this->postJson('/api/transport/trips', ['assignment_id' => $f['assignment']['id']])->assertOk()->json('data');
        $before = $this->httpSnapshot($f);
        $this->postJson('/api/transport/trips', ['assignment_id' => $f['assignment']['id']])->assertOk()->assertJsonPath('data.id', $trip['id']);
        $this->getJson('/api/transport/trips/'.$trip['id'])->assertOk();
        $this->assertSame($before, $this->httpSnapshot($f));
        $this->httpAs(User::withoutGlobalScopes()->findOrFail($f['unassigned']->id));
        $this->getJson('/api/transport/trips/'.$trip['id'])->assertForbidden();
        $this->postJson('/api/transport/trips', ['assignment_id' => $f['assignment']['id']])->assertForbidden();
    }

    public function test_authorization_creation_revocation_and_distinct_permissions(): void
    {
        $f = $this->journey();
        $this->httpAs($f['actor']);
        $input = [
            'assignment_id' => $f['assignment']['id'], 'kind' => 'guardian_recipient',
            'learner_id' => $f['learner']->id, 'stop_id' => $f['stop'],
            'guardian_id' => $f['guardian'], 'guardian_link_id' => $f['link'],
            'valid_from' => '2026-11-09', 'valid_to' => '2026-11-09', 'reason' => 'Explicit HTTP recipient authorization.',
        ];
        $grant = $this->postJson('/api/transport/authorizations', $input)->assertCreated()->json('data');
        $this->getJson('/api/transport/authorizations/'.$grant['id'])->assertOk();
        $this->postJson('/api/transport/authorizations/'.$grant['id'].'/revoke', [
            'expected_version' => $grant['version'], 'reason' => 'Recipient authority revoked.',
        ])->assertOk();
        $this->postJson('/api/transport/authorizations/'.$grant['id'].'/revoke', [
            'expected_version' => $grant['version'], 'reason' => 'Stale revocation rejected.',
        ])->assertConflict();
        $this->removePermission($f['actor'], 'authorize_transport_handover');
        $this->postJson('/api/transport/authorizations', $input)->assertForbidden();
        $this->postJson('/api/transport/authorizations', [
            'assignment_id' => $f['assignment']['id'], 'kind' => 'school_receiver',
            'staff_user_id' => $f['receiver']->id, 'valid_from' => '2026-11-09', 'valid_to' => '2026-11-09',
            'reason' => 'Management assigns school handover responsibility.',
        ])->assertCreated();
    }

    public function test_pickup_and_school_arrival_preserve_actual_people_and_receipt(): void
    {
        $f = $this->journey();
        $this->httpAs($f['actor']);
        $input = $this->input($f, 'pickup', $f['guardianGrant']['id']);
        $picked = $this->postJson('/api/transport/rosters/'.$f['roster'].'/events', $input)->assertCreated()->json('data');
        $this->assertSame($f['guardian'], $picked['events'][0]['from_person']['id']);
        $this->assertSame($f['actor']->id, $picked['events'][0]['to_person']['id']);
        $this->postJson('/api/transport/rosters/'.$f['roster'].'/events', $input)->assertConflict();
        $arrival = $this->input($f, 'school_arrival', $f['schoolGrant']['id']);
        $this->postJson('/api/transport/rosters/'.$f['roster'].'/events', $arrival)->assertUnprocessable();
        $this->httpAs($f['receiver']);
        $arrived = $this->postJson('/api/transport/rosters/'.$f['roster'].'/events', $arrival)->assertCreated()->json('data');
        $this->assertSame('at_school', $arrived['state']);
        $this->assertSame($f['receiver']->id, $arrived['events'][1]['confirmed_by']);
        $this->assertSame($f['actor']->id, $arrived['events'][1]['from_person']['id']);
        $this->assertSame($f['receiver']->id, $arrived['events'][1]['to_person']['id']);
        $this->getJson('/api/transport/rosters/'.$f['roster'].'/journey')->assertOk();
    }

    public function test_failed_dropoff_follow_up_and_resolution_keep_original_custody_evidence(): void
    {
        $f = $this->journey('from_school');
        $this->httpAs($f['actor']);
        $departure = $this->postJson('/api/transport/rosters/'.$f['roster'].'/events', $this->input($f, 'school_departure', $f['schoolGrant']['id']))->assertCreated()->json('data');
        $failed = $this->postJson('/api/transport/rosters/'.$f['roster'].'/events', [
            'expected_version' => $departure['version'], 'boundary' => 'destination_dropoff', 'outcome' => 'failed',
            'crew_user_id' => $f['actor']->id, 'occurred_at' => CarbonImmutable::now('UTC')->toIso8601String(),
            'receipt_method' => 'none', 'failure_reason' => 'person_unavailable',
            'follow_up' => 'Crew retains custody and contacts school leadership.',
        ])->assertCreated()->json('data');
        $failureId = $failed['events'][1]['id'];
        $original = DB::table('transport_journey_events')->where('id', $failureId)->first();
        $this->assertSame('on_board', $failed['state']);
        $this->assertSame($f['actor']->id, $failed['custody_user_id']);
        $exception = $this->getJson('/api/transport/exceptions/'.$failureId)->assertOk()->json('data');
        $action = [
            'expected_version' => $exception['version'], 'expected_journey_version' => $exception['journey_version'],
            'action' => 'follow_up', 'note' => 'Custody retained while authorized collection is arranged.',
        ];
        $followed = $this->postJson('/api/transport/exceptions/'.$failureId.'/actions', $action)->assertCreated()->json('data');
        $this->assertSame('open', $followed['status']);
        $this->postJson('/api/transport/exceptions/'.$failureId.'/actions', $action)->assertConflict();
        $completed = $this->postJson('/api/transport/rosters/'.$f['roster'].'/events', [
            ...$this->input($f, 'destination_dropoff', $f['guardianGrant']['id']), 'receipt_method' => 'witnessed_verbal',
        ])->assertCreated()->json('data');
        $this->assertContains($failureId, $completed['unresolved_failure_ids']);
        $current = $this->getJson('/api/transport/exceptions/'.$failureId)->assertOk()->json('data');
        $this->postJson('/api/transport/exceptions/'.$failureId.'/actions', [
            'expected_version' => $current['version'], 'expected_journey_version' => $current['journey_version'],
            'action' => 'resolve', 'note' => 'Later successful dropoff evidence independently checked.',
            'resolution_event_id' => $completed['events'][2]['id'],
        ])->assertCreated()->assertJsonPath('data.status', 'resolved');
        $this->assertEquals($original, DB::table('transport_journey_events')->where('id', $failureId)->first());
        $this->getJson('/api/transport/rosters/'.$f['roster'].'/journey')->assertOk()->assertJsonPath('data.unresolved_failure_ids', []);
    }

    public function test_attendance_outcomes_and_completion_do_not_invent_handovers(): void
    {
        $f = $this->journey();
        $this->httpAs($f['actor']);
        $trip = $this->tripId($f);
        $before = $this->getJson('/api/transport/trips/'.$trip.'/attendance')->assertOk()->json('data');
        $incomplete = $this->postJson('/api/transport/trips/'.$trip.'/complete', [
            'expected_version' => $before['version'], 'reason' => 'Expected learner is not yet accounted for.',
        ])->assertOk()->json('data');
        $this->assertSame('incomplete', $incomplete['completion_history'][0]['status']);
        $journey = $this->getJson('/api/transport/rosters/'.$f['roster'].'/journey')->assertOk()->json('data');
        $this->postJson('/api/transport/rosters/'.$f['roster'].'/outcome', [
            'expected_version' => $journey['version'], 'outcome' => 'cancelled', 'reason' => 'Approved non-travel cancellation before boarding.',
        ])->assertCreated()->assertJsonPath('data.attendance.cancelled', 1);
        $this->getJson('/api/transport/rosters/'.$f['roster'].'/journey')->assertOk()->assertJsonPath('data.state', 'cancelled');
        $this->postJson('/api/transport/trips/'.$trip.'/complete', [
            'expected_version' => $incomplete['version'], 'reason' => 'Stale attendance basis rejected.',
        ])->assertConflict();
        $current = $this->getJson('/api/transport/trips/'.$trip.'/attendance')->assertOk()->json('data');
        $complete = $this->postJson('/api/transport/trips/'.$trip.'/complete', [
            'expected_version' => $current['version'], 'reason' => 'Every roster outcome accounted for.',
        ])->assertOk()->json('data');
        $this->assertSame('completed', $complete['completion_history'][1]['status']);
        $this->assertSame(0, $complete['attendance']['transported_legs']);
        $this->assertSame(0, DB::table('transport_journey_events')->where('school_id', $f['school']->id)->count());
    }

    public function test_foreign_trips_rosters_authorizations_and_grants_fail_both_ways(): void
    {
        $a = $this->journey();
        $b = $this->journey();
        foreach ([[$a, $b], [$b, $a]] as [$own,$foreign]) {
            $this->httpAs($own['actor']);
            $this->getJson('/api/transport/trips/'.$this->tripId($foreign))->assertNotFound();
            $this->getJson('/api/transport/trips/'.$this->tripId($foreign).'/attendance')->assertNotFound();
            $this->getJson('/api/transport/rosters/'.$foreign['roster'].'/journey')->assertNotFound();
            $this->getJson('/api/transport/authorizations/'.$foreign['guardianGrant']['id'])->assertNotFound();
            $this->postJson('/api/transport/rosters/'.$foreign['roster'].'/events', $this->input($own, 'pickup', $own['guardianGrant']['id']))->assertNotFound();
            $this->postJson('/api/transport/rosters/'.$own['roster'].'/events', $this->input($own, 'pickup', $foreign['guardianGrant']['id']))->assertNotFound();
        }
    }

    public function test_revoked_authority_and_fabricated_event_fields_do_not_append_evidence(): void
    {
        $f = $this->journey();
        $this->httpAs($f['actor']);
        $input = $this->input($f, 'pickup', $f['guardianGrant']['id']);
        $before = $this->httpSnapshot($f);
        foreach (['from_person', 'to_person', 'confirmed_by', 'state_after', 'recorded_by'] as $field) {
            $this->postJson('/api/transport/rosters/'.$f['roster'].'/events', [...$input, $field => 'fabricated'])
                ->assertUnprocessable()->assertJsonValidationErrors($field);
        }
        $this->assertSame($before, $this->httpSnapshot($f));
        $this->postJson('/api/transport/authorizations/'.$f['guardianGrant']['id'].'/revoke', [
            'expected_version' => $f['guardianGrant']['version'], 'reason' => 'Authority no longer valid.',
        ])->assertOk();
        $before = $this->httpSnapshot($f);
        $this->postJson('/api/transport/rosters/'.$f['roster'].'/events', $input)->assertUnprocessable();
        $this->assertSame($before, $this->httpSnapshot($f));
    }

    public function test_recording_permission_does_not_grant_concrete_crew_responsibility(): void
    {
        $f = $this->journey();
        $this->httpAs($f['receiver']);
        $before = $this->httpSnapshot($f);
        $this->postJson('/api/transport/rosters/'.$f['roster'].'/events', $this->input($f, 'pickup', $f['guardianGrant']['id']))->assertForbidden();
        $this->postJson('/api/transport/rosters/'.$f['roster'].'/outcome', [
            'expected_version' => $this->events()->show($f['actor'], $f['roster'])['version'],
            'outcome' => 'cancelled', 'reason' => 'Unassigned staff cannot declare attendance.',
        ])->assertForbidden();
        $this->assertSame($before, $this->httpSnapshot($f));
    }
}
