<?php

namespace Tests\Feature;

use App\Http\Resources\TeacherPortalSafeResource;
use App\Models\User;
use App\Services\TeacherPortal\TeacherDeviceService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\Support\Database\RoleBuilder;
use Tests\Support\Database\SchoolBuilder;
use Tests\Support\Database\TeacherBuilder;
use Tests\Support\Database\UserBuilder;
use Tests\TestCase;

class TeacherPortalMobileTest extends TestCase
{
    use DatabaseTransactions;

    private object $school;

    private object $role;

    private User $teacher;

    protected function setUp(): void
    {
        parent::setUp();

        $this->school = SchoolBuilder::create();

        $this->role = RoleBuilder::create([
            'role_name' => 'Teacher',
        ]);

        $this->teacher = $this->makeTeacher();
    }

    public function test_devices_are_encrypted_idempotent_bounded_and_push_disabled(): void
    {
        config([
            'teacher_portal.device_limit' => 2,
        ]);

        $service = app(
            TeacherDeviceService::class
        );

        $first = $service->register(
            $this->teacher,
            [
                'device_identifier' => 'private-id',
                'platform' => 'android',
                'push_token' => 'private-token',
            ]
        );

        $again = $service->register(
            $this->teacher,
            [
                'device_identifier' => 'private-id',
                'platform' => 'android',
                'push_token' => 'new-token',
            ]
        );

        $this->assertSame(
            $first->id,
            $again->id
        );

        $stored = DB::table(
            'teacher_portal_devices'
        )
            ->where('id', $first->id)
            ->first();

        $this->assertNotSame(
            'private-id',
            $stored->device_identifier_hash
        );

        $this->assertSame(
            'new-token',
            Crypt::decryptString(
                $stored->push_token_encrypted
            )
        );

        $this->assertFalse(
            (bool) $stored->push_enabled
        );

        $this->assertArrayNotHasKey(
            'push_token_encrypted',
            $first->toArray()
        );

        $service->register(
            $this->teacher,
            [
                'device_identifier' => 'second',
                'platform' => 'web',
            ]
        );

        $this->expectException(
            ValidationException::class
        );

        $service->register(
            $this->teacher,
            [
                'device_identifier' => 'third',
                'platform' => 'ios',
            ]
        );
    }

    public function test_cross_user_revoke_is_denied_and_owner_revoke_clears_secret(): void
    {
        $service = app(
            TeacherDeviceService::class
        );

        $device = $service->register(
            $this->teacher,
            [
                'device_identifier' => 'mine',
                'platform' => 'ios',
                'push_token' => 'secret',
            ]
        );

        $other = $this->makeTeacher();

        try {
            $service->revoke(
                $other,
                $device->id
            );

            $this->fail(
                'Cross-user revoke succeeded.'
            );
        } catch (NotFoundHttpException) {
            $this->assertTrue(true);
        }

        $service->revoke(
            $this->teacher,
            $device->id
        );

        $stored = DB::table(
            'teacher_portal_devices'
        )
            ->where('id', $device->id)
            ->first();

        $this->assertNotNull(
            $stored->revoked_at
        );

        $this->assertNull(
            $stored->push_token_encrypted
        );

        $this->assertFalse(
            (bool) $stored->push_enabled
        );
    }

    public function test_teacher_mobile_routes_have_explicit_permissions_and_safe_resources_strip_secrets(): void
    {
        $routes = collect(
            Route::getRoutes()->getRoutes()
        );

        foreach ([
            'api/teacher/dashboard',
            'api/teacher/assignments',
            'api/teacher/attendance/registers',
            'api/teacher/attendance/registers/{register}/records/{record}',
            'api/teacher/lesson-plans/{lessonPlan}/submit',
            'api/teacher/homework/{assignment}/submissions/{submission}/feedback',
            'api/teacher/assessments/{exam}',
            'api/teacher/marks-entry/{paper}',
            'api/teacher/devices',
        ] as $uri) {
            $route = $routes->first(
                fn ($route) => $route->uri() === $uri
            );

            $this->assertNotNull(
                $route,
                $uri
            );

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

        $safe = (
            new TeacherPortalSafeResource([
                'title' => 'safe',
                'school_id' => 'hidden',
                'storage_path' => 'hidden',
                'private_teacher_notes' => 'hidden',
                'nested' => [
                    'approved_by' => 'hidden',
                    'value' => 'safe',
                ],
            ])
        )->toArray(
            Request::create('/')
        );

        $this->assertSame(
            [
                'title' => 'safe',
                'nested' => [
                    'value' => 'safe',
                ],
            ],
            $safe
        );
    }

    public function test_phase_one_has_no_provider_payment_ai_or_finance_write_integration(): void
    {
        $files = [
            app_path(
                'Http/Controllers/Api/TeacherPortalMobileController.php'
            ),
            app_path(
                'Services/TeacherPortal/TeacherDeviceService.php'
            ),
            app_path(
                'Services/TeacherPortal/TeacherPortalMobileService.php'
            ),
        ];

        $source = strtolower(
            implode(
                '',
                array_map(
                    'file_get_contents',
                    $files
                )
            )
        );

        foreach ([
            'mpesa',
            'firebase',
            'resend',
            'africastalking',
            'openai',
            'learner_fee_ledger',
        ] as $forbidden) {
            $this->assertStringNotContainsString(
                $forbidden,
                $source
            );
        }
    }

    private function makeTeacher(): User
    {
        $user = UserBuilder::create(
            $this->school,
            $this->role
        );

        TeacherBuilder::create(
            $this->school,
            $user
        );

        return User::with('role')
            ->findOrFail($user->id);
    }
}
