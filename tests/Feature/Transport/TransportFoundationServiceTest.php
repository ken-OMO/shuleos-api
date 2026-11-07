<?php

declare(strict_types=1);

namespace Tests\Feature\Transport;

use App\Models\AuditLog;
use App\Models\User;
use App\Services\SchoolSetupReadinessService;
use App\Services\Transport\TransportFoundationService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\Support\Database\RoleBuilder;
use Tests\Support\Database\SchoolBuilder;
use Tests\Support\Database\UserBuilder;
use Tests\TestCase;

class TransportFoundationServiceTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $config = DB::connection()->getConfig();

        if (! app()->environment('testing')
            || ($config['driver'] ?? null) !== 'pgsql'
            || ($config['database'] ?? null) !== 'shuleos_assessment_fk_test'
            || ! empty($config['url'])
            || DB::selectOne('SELECT current_database() AS name')->name
                !== 'shuleos_assessment_fk_test') {
            throw new \RuntimeException('Exact isolated Transport test database required.');
        }

        $this->mock(SchoolSetupReadinessService::class)
            ->shouldReceive('isReady')->andReturn(true);
    }

    public function test_resources_are_normalized_versioned_and_audited(): void
    {
        $actor = $this->actor();
        $service = app(TransportFoundationService::class);

        foreach (['day', 'day_and_boarding'] as $mode) {
            DB::table('schools')->where('id', $actor->school_id)
                ->update(['operating_mode' => $mode]);

            foreach ($this->inputs($actor) as $kind => $input) {
                $row = $service->create($actor, $kind, $input);
                $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $row['version']);
                $this->assertArrayNotHasKey('school_id', $row);
                $this->assertArrayNotHasKey('created_by', $row);
                $this->assertSame($row, $service->show($actor, $kind, $row['id']));
                $this->assertDatabaseHas('audit_logs', [
                    'record_id' => $row['id'],
                    'module' => 'Transport',
                    'action' => 'create',
                ]);
            }
        }
    }

    public function test_noop_retains_version_and_restoring_values_rejects_old_version(): void
    {
        $actor = $this->actor();
        $service = app(TransportFoundationService::class);

        foreach ($this->inputs($actor) as $kind => $input) {
            $row = $service->create($actor, $kind, $input);
            $auditCount = DB::table('audit_logs')->count();

            $same = $service->update($actor, $kind, $row['id'], [
                'active' => true, 'expected_version' => $row['version'],
            ]);
            $this->assertSame($row, $same);
            $this->assertSame($auditCount, DB::table('audit_logs')->count());

            $retired = $service->update($actor, $kind, $row['id'], [
                'active' => false, 'expected_version' => $row['version'],
            ]);
            $restored = $service->update($actor, $kind, $row['id'], [
                'active' => true, 'expected_version' => $retired['version'],
            ]);
            $this->assertNotSame($row['version'], $restored['version']);

            $auditCount = DB::table('audit_logs')->count();
            $this->denied(fn () => $service->update($actor, $kind, $row['id'], [
                'active' => false, 'expected_version' => $row['version'],
            ]), 409);
            $this->assertSame($auditCount, DB::table('audit_logs')->count());
            $this->assertSame($restored, $service->show($actor, $kind, $row['id']));
        }
    }

    public function test_foreign_resources_and_route_references_fail_both_ways(): void
    {
        $a = $this->actor();
        $b = $this->actor();
        $service = app(TransportFoundationService::class);

        foreach ([[$a, $b], [$b, $a]] as [$own, $foreign]) {
            $route = $service->create($foreign, 'route', [
                'route_code' => 'R-'.Str::random(12), 'route_name' => 'Foreign',
            ]);
            $this->denied(fn () => $service->show($own, 'route', $route['id']), 404);
            $this->denied(fn () => $service->update($own, 'route', $route['id'], [
                'route_name' => 'Changed', 'expected_version' => $route['version'],
            ]), 404);
            $this->denied(fn () => $service->create($own, 'stop', [
                'route_id' => $route['id'], 'direction' => 'to_school',
                'stop_name' => 'Foreign stop', 'stop_order' => 1,
            ]), 404);
        }
    }

    public function test_unknown_server_fields_and_invalid_values_are_rejected(): void
    {
        $actor = $this->actor();
        $service = app(TransportFoundationService::class);

        foreach ([
            ['school_id' => $actor->school_id],
            ['created_by' => $actor->id],
            ['lock_version' => 2],
            ['registration_number' => '   '],
            ['learner_capacity' => 0],
            ['ownership_type' => 'unknown'],
        ] as $override) {
            try {
                $service->create($actor, 'vehicle', array_merge([
                    'registration_number' => 'BUS-'.Str::random(12),
                    'ownership_type' => 'school_owned',
                    'learner_capacity' => 20,
                ], $override));
                $this->fail('Invalid vehicle input accepted.');
            } catch (ValidationException $exception) {
                $this->assertNotEmpty($exception->errors());
            }
        }

        $this->assertSame(0, DB::table('transport_vehicles')
            ->where('school_id', $actor->school_id)->count());
    }

    public function test_duplicate_codes_and_retired_route_stop_creation_are_rejected(): void
    {
        $actor = $this->actor();
        $service = app(TransportFoundationService::class);
        $code = 'R-'.Str::upper(Str::random(12));
        $route = $service->create($actor, 'route', [
            'route_code' => $code, 'route_name' => 'Route',
        ]);

        $this->denied(fn () => $service->create($actor, 'route', [
            'route_code' => ' '.strtolower($code).' ', 'route_name' => 'Duplicate',
        ]), 409);

        $service->update($actor, 'route', $route['id'], [
            'active' => false, 'expected_version' => $route['version'],
        ]);

        $this->denied(fn () => $service->create($actor, 'stop', [
            'route_id' => $route['id'], 'direction' => 'to_school',
            'stop_name' => 'Stop', 'stop_order' => 1,
        ]), 422);
    }

    public function test_management_and_read_permissions_are_separate(): void
    {
        $manager = $this->actor(['manage_transport']);
        $reader = $this->actor(['view_transport_operations']);
        $service = app(TransportFoundationService::class);

        $route = $service->create($manager, 'route', [
            'route_code' => 'R-'.Str::random(12), 'route_name' => 'Route',
        ]);
        $this->denied(fn () => $service->show($manager, 'route', $route['id']), 403);
        $this->denied(fn () => $service->create($reader, 'route', [
            'route_code' => 'R-'.Str::random(12), 'route_name' => 'Forbidden',
        ]), 403);
    }

    public function test_audit_failure_rolls_back_updates_and_version(): void
    {
        $actor = $this->actor();
        $service = app(TransportFoundationService::class);
        $row = $service->create($actor, 'vehicle', [
            'registration_number' => 'BUS-'.Str::random(12),
            'ownership_type' => 'school_owned', 'learner_capacity' => 20,
        ]);
        $original = AuditLog::getEventDispatcher();
        $this->assertNotNull($original);
        AuditLog::setEventDispatcher(clone $original);

        try {
            AuditLog::creating(static function (): void {
                throw new \RuntimeException('Transport service audit failure.');
            });

            try {
                $service->update($actor, 'vehicle', $row['id'], [
                    'learner_capacity' => 25, 'expected_version' => $row['version'],
                ]);
                $this->fail('Failed audit allowed update.');
            } catch (\RuntimeException $exception) {
                $this->assertSame('Transport service audit failure.', $exception->getMessage());
            }

            $this->assertSame($row, $service->show($actor, 'vehicle', $row['id']));
        } finally {
            AuditLog::setEventDispatcher($original);
        }
    }

    private function inputs(User $actor): array
    {
        $route = app(TransportFoundationService::class)->create($actor, 'route', [
            'route_code' => 'R-'.Str::random(12), 'route_name' => 'Parent route',
        ]);

        return [
            'vehicle' => [
                'registration_number' => ' bus-'.Str::random(12).' ',
                'ownership_type' => 'contracted', 'learner_capacity' => 20,
            ],
            'route' => [
                'route_code' => ' r-'.Str::random(12).' ', 'route_name' => ' Route ',
            ],
            'stop' => [
                'route_id' => $route['id'], 'direction' => 'to_school',
                'stop_name' => ' Stop ', 'stop_order' => 1,
            ],
        ];
    }

    private function actor(array $permissions = [
        'manage_transport', 'view_transport_operations',
    ]): User
    {
        $school = SchoolBuilder::create([
            'operating_mode' => 'day', 'lifecycle_state' => 'active',
        ]);
        $role = RoleBuilder::create();
        $row = UserBuilder::create($school, $role);

        foreach ($permissions as $permission) {
            $id = DB::table('permissions')
                ->where('permission_name', $permission)->value('id');
            $this->assertNotNull($id);
            DB::table('role_permissions')->insert([
                'id' => (string) Str::uuid(),
                'role_id' => $role->id, 'permission_id' => $id,
            ]);
        }

        return User::withoutGlobalScopes()->findOrFail($row->id);
    }

    private function denied(callable $operation, int $status): void
    {
        try {
            $operation();
            $this->fail('Invalid Transport operation succeeded.');
        } catch (HttpExceptionInterface $exception) {
            $this->assertSame($status, $exception->getStatusCode());
        }
    }
}
