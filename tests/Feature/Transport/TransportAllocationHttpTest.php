<?php

declare(strict_types=1);

namespace Tests\Feature\Transport;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\TransportHttpTestCase;

class TransportAllocationHttpTest extends TransportHttpTestCase
{
    public function test_assignments_can_be_created_read_ended_and_reject_stale_end_requests(): void
    {
        $f = $this->fixture();
        $this->httpAs($f['actor']);
        $schedule = DB::table('transport_dated_assignments')->where('id', $f['assignment']['id'])->value('schedule_id');
        $created = $this->postJson('/api/transport/assignments', [
            'schedule_id' => $schedule, 'service_date' => '2026-11-10',
            'vehicle_id' => $f['vehicle'], 'driver_id' => $f['actor']->id,
        ])->assertCreated()->json('data');
        $this->getJson('/api/transport/assignments/'.$created['id'])->assertOk();
        $this->postJson('/api/transport/assignments/'.$created['id'].'/end', [
            'expected_version' => $created['version'], 'reason' => 'Run cancelled before reservations.',
        ])->assertOk()->assertJsonPath('data.active', false);
        $this->postJson('/api/transport/assignments/'.$created['id'].'/end', [
            'expected_version' => $created['version'], 'reason' => 'Stale cancellation rejected.',
        ])->assertConflict();
    }

    public function test_reservation_capacity_and_end_history_are_enforced_over_http(): void
    {
        $f = $this->fixture();
        DB::table('transport_vehicles')->where('id', $f['vehicle'])->update(['learner_capacity' => 1]);
        $this->httpAs($f['actor']);
        $input = ['assignment_id' => $f['assignment']['id'], 'learner_id' => $f['other']->id, 'stop_id' => $f['stop']];
        $before = $this->httpSnapshot($f);
        $this->postJson('/api/transport/reservations', $input)->assertConflict();
        $this->assertSame($before, $this->httpSnapshot($f));
        $this->postJson('/api/transport/reservations/'.$f['reservation']['id'].'/end', [
            'expected_version' => $f['reservation']['version'], 'reason' => 'Reservation ended prospectively.',
        ])->assertOk()->assertJsonPath('data.active', false);
        $created = $this->postJson('/api/transport/reservations', $input)->assertCreated()->json('data');
        $this->getJson('/api/transport/reservations/'.$created['id'])->assertOk();
        $this->postJson('/api/transport/reservations', [...$input, 'seat_slot' => 99])->assertUnprocessable()->assertJsonValidationErrors('seat_slot');
        $this->postJson('/api/transport/reservations/'.$created['id'].'/end', [
            'expected_version' => $created['version'], 'reason' => 'Reservation ended before capture.',
        ])->assertOk();
        $this->postJson('/api/transport/reservations/'.$created['id'].'/end', [
            'expected_version' => $created['version'], 'reason' => 'Stale end request rejected.',
        ])->assertConflict();
    }

    public function test_recurring_enrolment_and_atomic_transfer_preserve_source_history(): void
    {
        $f = $this->fixture();
        $this->httpAs($f['actor']);
        $sourceSchedule = DB::table('transport_dated_assignments')->where('id', $f['assignment']['id'])->value('schedule_id');
        $episode = $this->postJson('/api/transport/enrolments', [
            'learner_id' => $f['other']->id, 'schedule_id' => $sourceSchedule, 'stop_id' => $f['stop'],
            'effective_from' => '2026-11-09', 'effective_to' => '2026-11-09', 'weekday_mask' => 1,
        ])->assertCreated()->json('data');
        $vehicle = $this->postJson('/api/transport/vehicles', [
            'registration_number' => 'TRANSFER-'.Str::random(8),
            'ownership_type' => 'school_owned', 'learner_capacity' => 2,
        ])->assertCreated()->json('data');
        $target = $this->postJson('/api/transport/schedules', $this->scheduleInput($f))->assertCreated()->json('data');
        $this->postJson('/api/transport/assignments', [
            'schedule_id' => $target['id'], 'service_date' => '2026-11-09',
            'vehicle_id' => $vehicle['id'], 'driver_id' => $f['unassigned']->id,
        ])->assertCreated();
        $transfer = [
            'expected_version' => $episode['version'], 'effective_from' => '2026-11-09',
            'schedule_id' => $target['id'], 'stop_id' => $f['stop'], 'reason' => 'Approved replacement service.',
        ];
        $moved = $this->postJson('/api/transport/enrolments/'.$episode['id'].'/transfer', $transfer)->assertOk()->json('data');
        $this->assertNotSame($episode['id'], $moved['target_episode']['id']);
        $this->getJson('/api/transport/enrolments/'.$episode['id'])->assertOk()->assertJsonPath('data.ended_before', '2026-11-09');
        $this->getJson('/api/transport/enrolments/'.$moved['target_episode']['id'])->assertOk();
        $before = $this->httpSnapshot($f);
        $this->postJson('/api/transport/enrolments/'.$episode['id'].'/transfer', $transfer)->assertConflict();
        $this->assertSame($before, $this->httpSnapshot($f));
        $this->assertDatabaseHas('learners', ['id' => $f['other']->id, 'mode_of_study' => 'day_scholar']);
    }

    public function test_foreign_allocations_and_references_are_rejected_in_both_directions(): void
    {
        $a = $this->fixture();
        $b = $this->fixture();
        foreach ([[$a, $b], [$b, $a]] as [$own,$foreign]) {
            $this->httpAs($own['actor']);
            $this->getJson('/api/transport/assignments/'.$foreign['assignment']['id'])->assertNotFound();
            $this->getJson('/api/transport/reservations/'.$foreign['reservation']['id'])->assertNotFound();
            $this->postJson('/api/transport/assignments/'.$foreign['assignment']['id'].'/end', [
                'expected_version' => $foreign['assignment']['version'], 'reason' => 'Foreign cancellation rejected.',
            ])->assertNotFound();
            $this->postJson('/api/transport/reservations', [
                'assignment_id' => $foreign['assignment']['id'], 'learner_id' => $own['other']->id, 'stop_id' => $own['stop'],
            ])->assertNotFound();
        }
    }
}
