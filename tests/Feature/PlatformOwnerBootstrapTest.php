<?php

namespace Tests\Feature;

use App\Console\Commands\BootstrapPlatformOwner;
use App\Services\Platform\PlatformOwnerBootstrapService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Tests\TestCase;

class PlatformOwnerBootstrapTest extends TestCase
{
    use DatabaseTransactions;

    private string $platformRoleId;

    protected function setUp(): void
    {
        parent::setUp();

        $roleId = DB::table('roles')
            ->where(
                'role_name',
                'Platform Owner'
            )
            ->whereNull(
                'school_id'
            )
            ->where(
                'system_role',
                true
            )
            ->where(
                'active',
                true
            )
            ->value('id');

        if (! $roleId) {
            throw new RuntimeException(
                'Required migrated Platform Owner role was not found.'
            );
        }

        $this->platformRoleId = (string) $roleId;

        /*
         * These tests exercise first-bootstrap behavior.
         * Ensure no root owner exists inside the transaction.
         */
        DB::table('users')
            ->where(
                'role_id',
                $this->platformRoleId
            )
            ->delete();
    }

    public function test_service_bootstraps_single_school_less_platform_owner(): void
    {
        $password = 'VeryStrong#Platform9';

        $user = app(
            PlatformOwnerBootstrapService::class
        )->bootstrap(
            [
                'email' => 'OWNER@EXAMPLE.TEST',
                'username' => 'Platform.Owner',
                'first_name' => 'Platform',
                'last_name' => 'Owner',
            ],
            $password
        );

        $this->assertNull(
            $user->school_id
        );

        $this->assertSame(
            $this->platformRoleId,
            (string) $user->role_id
        );

        $this->assertSame(
            'platform.owner',
            $user->username
        );

        $this->assertSame(
            'owner@example.test',
            $user->email
        );

        $this->assertTrue(
            $user->active
        );

        $this->assertTrue(
            $user->first_login
        );

        $this->assertTrue(
            $user->temporary_password
        );

        $this->assertNotNull(
            $user->temporary_password_expires_at
        );

        $this->assertNotNull(
            $user->force_password_reset_at
        );

        $this->assertNull(
            $user->email_verified_at
        );

        $this->assertNull(
            $user->activated_at
        );

        $this->assertSame(
            1,
            (int) $user->auth_generation
        );

        $this->assertTrue(
            Hash::check(
                $password,
                $user->password_hash
            )
        );

        $this->assertDatabaseHas(
            'audit_logs',
            [
                'user_id' => $user->id,
                'action' => 'platform_owner_bootstrapped',
                'school_id' => null,
            ]
        );
    }

    public function test_service_refuses_second_platform_owner(): void
    {
        $service = app(
            PlatformOwnerBootstrapService::class
        );

        $service->bootstrap(
            [
                'email' => 'first-owner@example.test',
                'username' => 'platform.first',
                'first_name' => 'First',
                'last_name' => 'Owner',
            ],
            'FirstOwner#Password9'
        );

        $this->expectException(
            RuntimeException::class
        );

        $service->bootstrap(
            [
                'email' => 'second-owner@example.test',
                'username' => 'platform.second',
                'first_name' => 'Second',
                'last_name' => 'Owner',
            ],
            'SecondOwner#Password9'
        );
    }

    public function test_service_rejects_weak_temporary_password(): void
    {
        $this->expectException(
            ValidationException::class
        );

        app(
            PlatformOwnerBootstrapService::class
        )->bootstrap(
            [
                'email' => 'owner@example.test',
                'username' => 'platform.owner',
                'first_name' => 'Platform',
                'last_name' => 'Owner',
            ],
            'weakpassword'
        );
    }

    public function test_service_rejects_password_containing_username(): void
    {
        $this->expectException(
            ValidationException::class
        );

        app(
            PlatformOwnerBootstrapService::class
        )->bootstrap(
            [
                'email' => 'owner@example.test',
                'username' => 'platform.owner',
                'first_name' => 'Platform',
                'last_name' => 'Owner',
            ],
            'Platform.Owner#Secure99'
        );
    }

    public function test_command_creates_owner_without_accepting_password_as_cli_option(): void
    {
        $this->artisan(
            'shuleos:bootstrap-platform-owner',
            [
                '--email' => 'console-owner@example.test',
                '--username' => 'platform.console',
                '--first-name' => 'Console',
                '--last-name' => 'Owner',
                '--yes' => true,
            ]
        )
            ->expectsQuestion(
                'Temporary password (minimum 16 characters, upper/lowercase, number and symbol)',
                'ConsoleBootstrap#94'
            )
            ->expectsQuestion(
                'Confirm temporary password',
                'ConsoleBootstrap#94'
            )
            ->assertSuccessful();

        $user = DB::table('users')
            ->where(
                'email',
                'console-owner@example.test'
            )
            ->first();

        $this->assertNotNull(
            $user
        );

        $this->assertNull(
            $user->school_id
        );

        $this->assertSame(
            $this->platformRoleId,
            (string) $user->role_id
        );

        $this->assertNotSame(
            'ConsoleBootstrap#94',
            $user->password_hash
        );
    }

    public function test_command_redacts_unexpected_internal_exception_message(): void
    {
        $sensitiveMessage = 'SQLSTATE[08006] password=super-secret database=platform';

        $this->mock(
            PlatformOwnerBootstrapService::class,
            function ($mock) use ($sensitiveMessage) {
                $mock
                    ->shouldReceive('bootstrap')
                    ->once()
                    ->andThrow(
                        new RuntimeException(
                            $sensitiveMessage
                        )
                    );
            }
        );

        $this->artisan(
            'shuleos:bootstrap-platform-owner',
            [
                '--email' => 'redaction@example.test',
                '--username' => 'platform.redaction',
                '--first-name' => 'Redaction',
                '--last-name' => 'Test',
                '--yes' => true,
            ]
        )
            ->expectsQuestion(
                'Temporary password (minimum 16 characters, upper/lowercase, number and symbol)',
                'Redaction#Password94'
            )
            ->expectsQuestion(
                'Confirm temporary password',
                'Redaction#Password94'
            )
            ->expectsOutput(
                'Platform Owner bootstrap failed.'
            )
            ->doesntExpectOutput(
                $sensitiveMessage
            )
            ->assertFailed();
    }

    public function test_command_has_no_password_option(): void
    {
        $command = app(
            BootstrapPlatformOwner::class
        );

        $definition = $command
            ->getDefinition();

        $this->assertFalse(
            $definition->hasOption(
                'password'
            )
        );

        $this->assertFalse(
            $definition->hasOption(
                'temporary-password'
            )
        );
    }
}
