<?php

namespace Tests\Feature;

use App\Http\Resources\ParentPortalArrayResource;
use App\Models\User;
use App\Services\ParentPortal\ParentDeviceService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\Support\Database\RoleBuilder;
use Tests\Support\Database\SchoolBuilder;
use Tests\Support\Database\UserBuilder;
use Tests\TestCase;

class ParentPortalMobileTest extends TestCase
{
    use DatabaseTransactions;

    private object $school;

    private object $role;

    private User $parent;

    protected function setUp(): void
    {
        parent::setUp();

        $this->school = SchoolBuilder::create();

        $this->role = RoleBuilder::create([
            'role_name' => 'Parent',
        ]);

        $this->parent = $this->makeParent('Parent One');
    }

    public function test_device_registration_is_encrypted_idempotent_bounded_and_never_enables_live_push(): void
    {
        config(['parent_portal.device_limit' => 2]);

        $service = app(ParentDeviceService::class);

        $first = $service->register($this->parent, [
            'device_identifier' => 'private-device-id',
            'platform' => 'android',
            'push_token' => 'private-push-token',
        ]);

        $again = $service->register($this->parent, [
            'device_identifier' => 'private-device-id',
            'platform' => 'android',
            'push_token' => 'new-private-token',
        ]);

        $this->assertSame($first->id, $again->id);

        $stored = DB::table('parent_portal_devices')
            ->where('id', $first->id)
            ->first();

        $this->assertNotSame(
            'private-device-id',
            $stored->device_identifier_hash
        );

        $this->assertNotSame(
            'new-private-token',
            $stored->push_token_encrypted
        );

        $this->assertSame(
            'new-private-token',
            Crypt::decryptString($stored->push_token_encrypted)
        );

        $this->assertFalse((bool) $stored->push_enabled);

        $this->assertArrayNotHasKey(
            'push_token_encrypted',
            $first->toArray()
        );

        $service->register($this->parent, [
            'device_identifier' => 'second',
            'platform' => 'web',
        ]);

        $this->expectException(ValidationException::class);

        $service->register($this->parent, [
            'device_identifier' => 'third',
            'platform' => 'ios',
        ]);
    }

    public function test_cross_user_device_revocation_is_denied_and_owner_revoke_clears_token(): void
    {
        $service = app(ParentDeviceService::class);

        $device = $service->register($this->parent, [
            'device_identifier' => 'mine',
            'platform' => 'ios',
            'push_token' => 'secret',
        ]);

        $other = $this->makeParent('Parent Two');

        try {
            $service->revoke($other, $device->id);

            $this->fail('A different parent revoked the device.');
        } catch (NotFoundHttpException) {
            $this->assertNull(
                DB::table('parent_portal_devices')
                    ->where('id', $device->id)
                    ->value('revoked_at')
            );
        }

        $service->revoke($this->parent, $device->id);

        $stored = DB::table('parent_portal_devices')
            ->where('id', $device->id)
            ->first();

        $this->assertNotNull($stored->revoked_at);
        $this->assertNull($stored->push_token_encrypted);
        $this->assertFalse((bool) $stored->push_enabled);
    }

    public function test_parent_routes_are_explicitly_permission_protected_and_resources_strip_sensitive_fields(): void
    {
        $routes = collect(Route::getRoutes()->getRoutes())
            ->filter(
                fn ($route) => str_starts_with(
                    $route->uri(),
                    'api/parent/'
                )
            );

        foreach ([
            'api/parent/dashboard',
            'api/parent/children',
            'api/parent/children/{learner}/results',
            'api/parent/children/{learner}/finance/statement',
            'api/parent/devices',
        ] as $uri) {
            $route = $routes->first(
                fn ($candidate) => $candidate->uri() === $uri
            );

            $this->assertNotNull($route, $uri);

            $this->assertNotEmpty(
                collect($route->gatherMiddleware())
                    ->first(
                        fn ($middleware) => str_starts_with(
                            $middleware,
                            'permission:'
                        )
                    ),
                $uri
            );
        }

        $data = (
            new ParentPortalArrayResource([
                'title' => 'Safe',
                'school_id' => 'hidden',
                'storage_path' => 'hidden',
                'provider_response' => 'hidden',
                'nested' => [
                    'approved_by' => 'hidden',
                    'value' => 'safe',
                ],
            ])
        )->toArray(Request::create('/'));

        $this->assertSame(
            [
                'title' => 'Safe',
                'nested' => [
                    'value' => 'safe',
                ],
            ],
            $data
        );
    }

    public function test_phase_one_contains_no_payment_push_sms_or_academic_write_integration(): void
    {
        $controller = file_get_contents(
            app_path('Http/Controllers/Api/ParentPortalMobileController.php')
        );

        $device = file_get_contents(
            app_path('Services/ParentPortal/ParentDeviceService.php')
        );

        $combined = strtolower($controller.$device);

        $this->assertStringNotContainsString('mpesa', $combined);
        $this->assertStringNotContainsString('firebase', $combined);
        $this->assertStringNotContainsString('send_sms', $combined);
        $this->assertStringNotContainsString('examresult::create', $combined);
        $this->assertStringNotContainsString('learner_fee_ledger', $combined);
    }

    private function makeParent(string $name): User
    {
        [$first, $last] = explode(' ', $name, 2);

        $user = UserBuilder::create(
            $this->school,
            $this->role,
            [
                'first_name' => $first,
                'last_name' => $last,
            ]
        );

        DB::table('parents')->insert([
            'id' => (string) Str::uuid(),
            'school_id' => $this->school->id,
            'user_id' => $user->id,
            'first_name' => $first,
            'last_name' => $last,
            'phone' => '+254700000001',
            'active' => true,
            'is_deleted' => false,
        ]);

        return User::with('role')->findOrFail($user->id);
    }
}
