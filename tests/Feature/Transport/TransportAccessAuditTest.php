<?php

declare(strict_types=1);

namespace Tests\Feature\Transport;

use App\Models\AuditLog;
use App\Models\User;
use App\Services\SchoolSetupReadinessService;
use App\Services\Transport\TransportAccessService;
use App\Services\Transport\TransportAuditService;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\Support\Database\RoleBuilder;
use Tests\Support\Database\SchoolBuilder;
use Tests\Support\Database\UserBuilder;
use Tests\Support\TransportSubscriptionFixture;
use Tests\TestCase;

class TransportAccessAuditTest extends TestCase
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

    public function test_permissions_are_provisioned_without_automatic_grants(): void
    {
        $permissions = [
            'manage_transport',
            'manage_transport_enrolments',
            'view_transport_operations',
            'record_transport_journeys',
            'authorize_transport_handover',
            'submit_transport_daily_reports',
            'review_transport_daily_reports',
        ];

        $before = DB::table('role_permissions')->count();
        $migration = require database_path(
            'migrations/2026_11_03_170000_add_transport_permissions.php'
        );

        $migration->up();
        $migration->up();

        foreach ($permissions as $permission) {
            $this->assertSame(
                1,
                DB::table('permissions')
                    ->where('permission_name', $permission)
                    ->where('module_name', 'transport')->count()
            );
        }

        $this->assertSame($before, DB::table('role_permissions')->count());
        $migration->down();
        $this->assertSame(
            7,
            DB::table('permissions')->whereIn('permission_name', $permissions)->count()
        );
    }

    public function test_day_and_mixed_school_users_need_no_teacher_record(): void
    {
        foreach (['day', 'day_and_boarding'] as $mode) {
            $actor = $this->fixture($mode);
            $resolved = app(TransportAccessService::class)->actor($actor, true);
            $this->assertSame((string) $actor->id, (string) $resolved->id);
            $this->assertSame($mode, $resolved->school->operating_mode);
        }
    }

    public function test_regular_transport_writes_reject_boarding_only_schools(): void
    {
        $actor = $this->fixture('boarding');
        $this->denied(
            fn () => app(TransportAccessService::class)->actor($actor, true),
            403
        );

        $this->assertSame(
            (string) $actor->id,
            (string) app(TransportAccessService::class)->actor($actor)->id
        );
    }

    public function test_stale_authenticated_models_cannot_hide_actor_deactivation(): void
    {
        $actor = $this->fixture();

        DB::table('users')->where('id', $actor->id)->update(['active' => false]);

        try {
            app(TransportAccessService::class)->actor($actor, true);
            $this->fail('Inactive actor was accepted.');
        } catch (AuthenticationException $exception) {
            $this->assertSame('Unauthenticated.', $exception->getMessage());
        }
    }

    public function test_tenant_ownership_is_checked_in_both_directions(): void
    {
        $a = $this->fixture();
        $b = $this->fixture();
        $access = app(TransportAccessService::class);

        foreach ([[$a, $b], [$b, $a]] as [$own, $foreign]) {
            $this->denied(
                fn () => $access->requireOwnership(
                    $own,
                    (object) ['school_id' => $foreign->school_id]
                ),
                404
            );
        }

        $forged = clone $a;
        $forged->school_id = $b->school_id;
        $this->denied(fn () => $access->actor($forged), 403);
    }

    public function test_management_permission_does_not_grant_journey_authority(): void
    {
        $actor = $this->fixture();
        $permission = DB::table('permissions')
            ->where('permission_name', 'manage_transport')->value('id');

        $this->assertNotNull($permission);

        DB::table('role_permissions')->insert([
            'id' => (string) Str::uuid(),
            'role_id' => $actor->role_id,
            'permission_id' => $permission,
        ]);

        $access = app(TransportAccessService::class);
        $access->requirePermission($actor, 'manage_transport');

        foreach ([
            'record_transport_journeys',
            'authorize_transport_handover',
            'submit_transport_daily_reports',
            'review_transport_daily_reports',
        ] as $permissionName) {
            $this->denied(
                fn () => $access->requirePermission($actor, $permissionName),
                403
            );
        }
    }

    public function test_audit_and_vehicle_mutation_commit_together(): void
    {
        $actor = $this->fixture();
        $id = (string) Str::uuid();

        DB::transaction(function () use ($actor, $id): void {
            $this->insertVehicle($actor, $id);
            app(TransportAuditService::class)->record(
                $actor,
                'create',
                'transport_vehicles',
                $id,
                [],
                ['learner_capacity' => 20]
            );
        });

        $this->assertDatabaseHas('transport_vehicles', ['id' => $id]);
        $this->assertDatabaseHas('audit_logs', [
            'school_id' => $actor->school_id,
            'user_id' => $actor->id,
            'module' => 'Transport',
            'record_id' => $id,
        ]);
    }

    public function test_thrown_or_cancelled_audit_rolls_back_vehicle_creation(): void
    {
        $actor = $this->fixture();
        $original = AuditLog::getEventDispatcher();
        $this->assertNotNull($original);

        foreach (['throw', 'cancel'] as $failure) {
            AuditLog::setEventDispatcher(clone $original);
            $id = (string) Str::uuid();

            try {
                AuditLog::creating(static function () use ($failure) {
                    if ($failure === 'throw') {
                        throw new \RuntimeException('Transport audit failure test.');
                    }

                    return false;
                });

                try {
                    DB::transaction(function () use ($actor, $id): void {
                        $this->insertVehicle($actor, $id);
                        app(TransportAuditService::class)->record(
                            $actor, 'create', 'transport_vehicles', $id
                        );
                    });

                    $this->fail('Audit failure was accepted.');
                } catch (\RuntimeException|\LogicException $exception) {
                    $this->assertSame(
                        $failure === 'throw'
                            ? 'Transport audit failure test.'
                            : 'Transport audit was not persisted.',
                        $exception->getMessage()
                    );
                }

                $this->assertDatabaseMissing('transport_vehicles', ['id' => $id]);
                $this->assertDatabaseMissing('audit_logs', ['record_id' => $id]);
            } finally {
                AuditLog::setEventDispatcher($original);
            }
        }
    }

    private function fixture(string $mode = 'day'): User
    {
        $school = SchoolBuilder::create([
            'operating_mode' => $mode,
            'lifecycle_state' => 'active',
        ]);
        TransportSubscriptionFixture::activate((string) $school->id);

        $row = UserBuilder::create($school, RoleBuilder::create());

        return User::withoutGlobalScopes()->findOrFail($row->id);
    }

    private function insertVehicle(User $actor, string $id): void
    {
        DB::table('transport_vehicles')->insert([
            'id' => $id,
            'school_id' => $actor->school_id,
            'registration_number' => 'BUS-'.Str::upper(Str::random(12)),
            'ownership_type' => 'school_owned',
            'learner_capacity' => 20,
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
        ]);
    }

    private function denied(callable $operation, int $status): void
    {
        try {
            $operation();
            $this->fail('Unauthorized Transport operation succeeded.');
        } catch (HttpExceptionInterface $exception) {
            $this->assertSame($status, $exception->getStatusCode());
        }
    }
}
