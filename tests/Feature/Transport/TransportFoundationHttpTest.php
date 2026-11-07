<?php

declare(strict_types=1);

namespace Tests\Feature\Transport;

use App\Services\Transport\TransportAuditService;
use App\Services\Transport\TransportScheduleService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\Support\TransportHttpTestCase;

class TransportFoundationHttpTest extends TransportHttpTestCase
{
    public function test_every_transport_route_requires_jwt_and_tenant_context(): void
    {
        $routes = collect(Route::getRoutes()->getRoutes())->filter(fn ($route) => str_starts_with($route->getName() ?? '', 'transport.'));
        $this->assertCount(35, $routes);
        foreach ($routes as $route) {
            $this->assertContains('jwt', $route->gatherMiddleware());
            $this->assertContains('tenant', $route->gatherMiddleware());
            $this->assertNotContains('module.permission', $route->gatherMiddleware());
            $uri = str_replace(['{resource}', '{id}'], ['vehicles', (string) Str::uuid()], $route->uri());
            $this->json($route->methods()[0], '/'.$uri)->assertUnauthorized();
        }
    }

    public function test_vehicle_route_and_stop_endpoints_create_read_and_reject_stale_updates(): void
    {
        $f = $this->fixture();
        $this->httpAs($f['actor']);
        foreach ([
            ['vehicles', ['registration_number' => 'HTTP-'.Str::random(8), 'ownership_type' => 'school_owned', 'learner_capacity' => 20], ['learner_capacity' => 21]],
            ['routes', ['route_code' => 'HTTP-'.Str::random(8), 'route_name' => 'HTTP route'], ['route_name' => 'Updated HTTP route']],
            ['stops', ['route_id' => $f['route'], 'direction' => 'to_school', 'stop_name' => 'HTTP stop', 'stop_order' => 2], ['stop_name' => 'Updated HTTP stop']],
        ] as [$resource, $input, $change]) {
            $created = $this->postJson('/api/transport/'.$resource, $input)->assertCreated()->json('data');
            $this->getJson('/api/transport/'.$resource.'/'.$created['id'])->assertOk()->assertJsonPath('data.id', $created['id']);
            $updated = $this->patchJson('/api/transport/'.$resource.'/'.$created['id'], ['expected_version' => $created['version'], ...$change])->assertOk()->json('data');
            $this->assertNotSame($created['version'], $updated['version']);
            $before = $this->httpSnapshot($f);
            $this->patchJson('/api/transport/'.$resource.'/'.$created['id'], ['expected_version' => $created['version'], ...$change])->assertConflict();
            $this->assertSame($before, $this->httpSnapshot($f));
        }
    }

    public function test_schedule_creation_state_noop_stale_token_and_immutable_definition(): void
    {
        $f = $this->fixture();
        $this->httpAs($f['actor']);
        $created = $this->postJson('/api/transport/schedules', $this->scheduleInput($f))->assertCreated()->json('data');
        $this->getJson('/api/transport/schedules/'.$created['id'])->assertOk()->assertJsonPath('data.active', true);
        $before = $this->httpSnapshot($f);
        $noop = $this->patchJson('/api/transport/schedules/'.$created['id'].'/state', [
            'expected_version' => $created['version'], 'active' => true, 'reason' => 'No change requested.',
        ])->assertOk()->json('data');
        $this->assertSame($created['version'], $noop['version']);
        $this->assertSame($before, $this->httpSnapshot($f));
        $retired = $this->patchJson('/api/transport/schedules/'.$created['id'].'/state', [
            'expected_version' => $created['version'], 'active' => false, 'reason' => 'Service retired prospectively.',
        ])->assertOk()->assertJsonPath('data.active', false)->json('data');
        $this->patchJson('/api/transport/schedules/'.$created['id'].'/state', [
            'expected_version' => $created['version'], 'active' => true, 'reason' => 'Stale token rejected.',
        ])->assertConflict();
        $this->patchJson('/api/transport/schedules/'.$created['id'].'/state', [
            'expected_version' => $retired['version'], 'active' => true, 'reason' => 'Definition change rejected.', 'departure_time' => '06:30',
        ])->assertUnprocessable()->assertJsonValidationErrors('departure_time');
    }

    public function test_invalid_schedule_windows_duplicate_codes_and_foreign_routes_are_rejected(): void
    {
        $f = $this->fixture();
        $foreign = $this->fixture();
        $this->httpAs($f['actor']);
        $input = $this->scheduleInput($f);
        $this->postJson('/api/transport/schedules', $input)->assertCreated();
        $this->postJson('/api/transport/schedules', $input)->assertConflict();
        $this->postJson('/api/transport/schedules', $this->scheduleInput($f, ['completion_time' => '06:00']))->assertUnprocessable();
        $this->postJson('/api/transport/schedules', $this->scheduleInput($f, ['route_id' => $foreign['route']]))->assertNotFound();
        $this->postJson('/api/transport/schedules', $this->scheduleInput($f, ['timezone' => 'Invalid/Zone']))->assertUnprocessable();
        $this->postJson('/api/transport/schedules', $this->scheduleInput($f, ['departure_time' => '24:00']))->assertUnprocessable();
    }

    public function test_catalogues_are_bounded_tenant_scoped_and_do_not_expose_identity_snapshots(): void
    {
        $a = $this->journey();
        $b = $this->journey();
        foreach ([$a, $b] as $f) {
            $this->httpAs($f['actor']);
            $before = $this->httpSnapshot($f);
            $result = $this->getJson('/api/transport/vehicles?limit=1')->assertOk()->assertJsonPath('meta.total', 1)->json('data');
            $this->assertSame([$f['vehicle']], array_column($result, 'id'));
            $this->getJson('/api/transport/vehicles?offset=100')->assertOk()->assertJsonPath('data', [])->assertJsonPath('meta.total', 1);
            $this->getJson('/api/transport/vehicles?limit=101')->assertUnprocessable();
            foreach (['routes', 'stops', 'schedules', 'assignments', 'reservations', 'enrolments', 'trips', 'authorizations'] as $resource) {
                $this->getJson('/api/transport/'.$resource)->assertOk();
            }
            $json = $this->getJson('/api/transport/authorizations')->assertOk()->getContent();
            $this->assertStringNotContainsString('identity_snapshot', $json);
            $this->assertStringNotContainsString('guardian_id', $json);
            $this->assertSame($before, $this->httpSnapshot($f));
        }
    }

    public function test_client_ownership_and_server_fields_are_rejected_without_silent_dropping(): void
    {
        $f = $this->fixture();
        $this->httpAs($f['actor']);
        $before = $this->httpSnapshot($f);
        foreach ([$f['school']->id, (string) Str::uuid(), null] as $schoolId) {
            $this->postJson('/api/transport/routes', ['route_code' => 'BAD', 'route_name' => 'Rejected ownership', 'school_id' => $schoolId])
                ->assertUnprocessable()->assertJsonValidationErrors('school_id');
        }
        $this->getJson('/api/transport/vehicles?school_id='.$f['school']->id)->assertUnprocessable()->assertJsonValidationErrors('school_id');
        foreach (['created_by', 'lock_version', 'id', 'unexpected'] as $field) {
            $this->postJson('/api/transport/routes', ['route_code' => 'BAD', 'route_name' => 'Rejected field', $field => 'fabricated'])
                ->assertUnprocessable()->assertJsonValidationErrors($field);
        }
        $this->assertSame($before, $this->httpSnapshot($f));
    }

    public function test_foreign_foundation_resources_and_schedules_fail_both_ways(): void
    {
        $a = $this->fixture();
        $b = $this->fixture();
        foreach ([[$a, $b], [$b, $a]] as [$own, $foreign]) {
            $this->httpAs($own['actor']);
            foreach (['vehicles' => $foreign['vehicle'], 'routes' => $foreign['route'], 'stops' => $foreign['stop']] as $resource => $id) {
                $this->getJson('/api/transport/'.$resource.'/'.$id)->assertNotFound();
                $this->patchJson('/api/transport/'.$resource.'/'.$id, ['expected_version' => str_repeat('a', 64), 'active' => false])->assertNotFound();
            }
            $schedule = DB::table('transport_dated_assignments')->where('id', $foreign['assignment']['id'])->value('schedule_id');
            $this->getJson('/api/transport/schedules/'.$schedule)->assertNotFound();
            $this->patchJson('/api/transport/schedules/'.$schedule.'/state', ['expected_version' => str_repeat('a', 64), 'active' => false, 'reason' => 'Foreign update rejected.'])->assertNotFound();
        }
    }

    public function test_management_view_and_enrolment_permissions_remain_separate(): void
    {
        $f = $this->fixture();
        $this->removePermission($f['actor'], 'manage_transport');
        $this->httpAs($f['actor']);
        $this->getJson('/api/transport/vehicles')->assertOk();
        $this->postJson('/api/transport/routes', [])->assertForbidden();
        $this->postJson('/api/transport/schedules', [])->assertForbidden();
        $this->postJson('/api/transport/assignments', [])->assertForbidden();
        $this->removePermission($f['actor'], 'manage_transport_enrolments');
        $this->postJson('/api/transport/reservations', [])->assertForbidden();
        $this->postJson('/api/transport/enrolments', [])->assertForbidden();
        $this->removePermission($f['actor'], 'view_transport_operations');
        $this->getJson('/api/transport/vehicles')->assertForbidden();
    }

    public function test_read_only_school_can_read_history_but_cannot_change_resources(): void
    {
        $f = $this->fixture();
        DB::table('schools')->where('id', $f['school']->id)->update(['lifecycle_state' => 'read_only']);
        $this->httpAs($f['actor']);
        $this->getJson('/api/transport/vehicles/'.$f['vehicle'])->assertOk();
        $before = $this->httpSnapshot($f);
        $this->postJson('/api/transport/routes', ['route_code' => 'RO', 'route_name' => 'Read only rejected'])->assertForbidden();
        $this->postJson('/api/transport/schedules', $this->scheduleInput($f))->assertForbidden();
        $this->assertSame($before, $this->httpSnapshot($f));
    }

    public function test_invalid_path_ids_and_historical_deletion_are_unavailable(): void
    {
        $f = $this->fixture();
        $this->httpAs($f['actor']);
        $this->getJson('/api/transport/vehicles/not-a-uuid')->assertNotFound();
        $this->deleteJson('/api/transport/vehicles/'.$f['vehicle'])->assertStatus(405);
        $this->getJson('/api/transport/unknown-resources')->assertNotFound();
    }

    public function test_schedule_audit_failure_rolls_back_create_and_retirement(): void
    {
        $f = $this->fixture();
        $service = app(TransportScheduleService::class);
        $created = $service->create($f['actor'], $this->scheduleInput($f));
        $before = $this->httpSnapshot($f);
        $this->mock(TransportAuditService::class)->shouldReceive('record')->andThrow(new \RuntimeException('Schedule strict audit failure.'));
        foreach ([
            fn () => app(TransportScheduleService::class)->create($f['actor'], $this->scheduleInput($f)),
            fn () => app(TransportScheduleService::class)->updateState($f['actor'], $created['id'], ['expected_version' => $created['version'], 'active' => false, 'reason' => 'Audit rollback fixture.']),
        ] as $operation) {
            try {
                $operation();
                $this->fail('Schedule audit failure was ignored.');
            } catch (\RuntimeException $exception) {
                $this->assertSame('Schedule strict audit failure.', $exception->getMessage());
            }
            $this->assertSame($before, $this->httpSnapshot($f));
        }
    }

    public function test_signed_token_is_rechecked_after_auth_generation_changes(): void
    {
        $f = $this->fixture();
        $this->httpAs($f['actor']);
        $this->getJson('/api/transport/vehicles')->assertOk();
        DB::table('users')->where('id', $f['actor']->id)->update(['auth_generation' => ((int) ($f['actor']->auth_generation ?: 1)) + 1]);
        $this->getJson('/api/transport/vehicles')->assertUnauthorized();
    }
}
