<?php

namespace Tests\Feature;

use App\Http\Resources\SchoolResource;
use App\Models\School;
use App\Models\User;
use App\Services\Administrator\AdministratorAuditService;
use App\Services\School\SchoolSettingsProvisioningService;
use App\Services\TeacherDuty\TeacherDutyOccurrenceCategoryProvisioningService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use RuntimeException;
use Tests\Support\Database\GradeBuilder;
use Tests\Support\Database\LearnerBuilder;
use Tests\Support\Database\MainSubscriptionBuilder;
use Tests\Support\Database\StreamBuilder;
use Tests\TestCase;

class PlatformSchoolOnboardingSecurityTest extends TestCase
{
    use DatabaseTransactions;

    private string $platformRoleId;

    private string $schoolAdminRoleId;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'jwt.secret' => str_repeat(
                'platform-school-onboarding-test-secret-',
                3
            ),
            'jwt.ttl' => 60,
        ]);

        $this->platformRoleId = $this->systemRole(
            'Platform Owner'
        );

        $this->schoolAdminRoleId = $this->systemRole(
            'School Admin'
        );
    }

    public function test_onboarding_saves_school_contacts_separately_from_admin_email(): void
    {
        $owner = $this->platformOwner();
        $adminEmail = 'contact-admin-'.Str::lower(Str::random(12)).'@example.test';

        $response = $this->withToken(JWTAuth::fromUser($owner))->postJson(
            '/api/admin/platform/schools',
            [
                'operating_mode' => 'day_and_boarding',
                'school_name' => 'Contact Capture School',
                'email' => 'school-office@example.test',
                'phone' => '+254700123456',
                'postal_address' => 'P.O. Box 123 - 00100, Nairobi',
                'physical_address' => 'School Road, Nairobi',
                'admin' => [
                    'first_name' => 'Contact',
                    'last_name' => 'Administrator',
                    'email' => $adminEmail,
                ],
            ]
        )->assertCreated();

        $schoolId = DB::table('users')->where('email', $adminEmail)->value('school_id');
        $this->assertNotNull($schoolId);

        $school = School::withoutGlobalScopes()->findOrFail($schoolId);
        $this->assertSame('school-office@example.test', $school->email);
        $this->assertSame('+254700123456', $school->phone);
        $this->assertSame('P.O. Box 123 - 00100, Nairobi', $school->postal_address);
        $this->assertSame('School Road, Nairobi', $school->physical_address);

        $payload = (new SchoolResource($school))->resolve(request());
        $this->assertSame($school->postal_address, $payload['postal_address']);
        $this->assertSame($school->physical_address, $payload['physical_address']);
        $this->assertNotSame($adminEmail, $payload['email']);
    }

    public function test_onboarding_rejects_invalid_school_contacts_without_creating_school(): void
    {
        $owner = $this->platformOwner();
        $name = 'Invalid contacts '.Str::random(12);

        $this->withToken(JWTAuth::fromUser($owner))->postJson(
            '/api/admin/platform/schools',
            [
                'operating_mode' => 'day_and_boarding',
                'school_name' => $name,
                'email' => 'invalid-email',
                'phone' => str_repeat('1', 31),
                'postal_address' => str_repeat('P', 256),
                'physical_address' => str_repeat('A', 256),
                'admin' => [
                    'first_name' => 'Contact',
                    'last_name' => 'Administrator',
                    'email' => 'invalid-contact-admin-'.Str::random(12).'@example.test',
                ],
            ]
        )->assertUnprocessable()->assertJsonValidationErrors([
            'email', 'phone', 'postal_address', 'physical_address',
        ]);

        $this->assertFalse(
            DB::table('schools')->where('school_name', $name)->exists()
        );
    }

    public function test_platform_owner_can_onboard_school_with_server_generated_identity_and_credentials(): void
    {
        $user = $this->platformOwner();

        $token = JWTAuth::fromUser(
            $user
        );

        $adminEmail = 'initial-admin-'
            .Str::lower(
                Str::random(6)
            )
            .'@example.test';

        $response = $this
            ->withToken($token)
            ->postJson(
                '/api/admin/platform/schools',
                [
                    'operating_mode' => 'day_and_boarding',
                    'school_name' => 'Onboarding Test School',
                    'timezone' => 'Africa/Nairobi',
                    'locale' => 'en',

                    'admin' => [
                        'first_name' => 'Initial',
                        'last_name' => 'Administrator',
                        'email' => $adminEmail,
                    ],
                ]
            );

        $response
            ->assertCreated()
            ->assertJsonPath(
                'success',
                true
            );

        $schoolCode = $response->json(
            'data.school.school_code'
        );

        $loginPrefix = $response->json(
            'data.school.login_prefix'
        );

        $username = $response->json(
            'data.initial_admin.username'
        );

        $temporaryPassword = $response->json(
            'data.initial_admin.temporary_password'
        );

        $this->assertIsString($schoolCode);
        $this->assertNotSame('', trim($schoolCode));

        $this->assertIsString($loginPrefix);
        $this->assertNotSame('', trim($loginPrefix));

        $this->assertIsString($username);
        $this->assertNotSame('', trim($username));

        $this->assertIsString($temporaryPassword);
        $this->assertGreaterThanOrEqual(
            16,
            strlen($temporaryPassword)
        );

        $school = DB::table('schools')
            ->where(
                'school_code',
                $schoolCode
            )
            ->first();

        $this->assertNotNull(
            $school
        );

        $this->assertSame(
            'Onboarding Test School',
            $school->school_name
        );

        $this->assertSame(
            'onboarding',
            $school->lifecycle_state
        );

        $this->assertFalse(
            (bool) $school->is_deleted
        );

        $settings = DB::table('school_settings')
            ->where('school_id', $school->id)
            ->get();

        $this->assertCount(1, $settings);

        $this->assertSame(
            '17:00:00',
            (string) $settings->first()->teacher_duty_report_deadline_time
        );

        $this->assertSame(
            120,
            (int) $settings->first()->teacher_duty_report_grace_minutes
        );

        $admin = DB::table('users')
            ->where(
                'email',
                $adminEmail
            )
            ->first();

        $this->assertNotNull(
            $admin
        );

        $this->assertSame(
            (string) $school->id,
            (string) $admin->school_id
        );

        $this->assertSame(
            $this->schoolAdminRoleId,
            (string) $admin->role_id
        );

        $this->assertSame(
            $username,
            $admin->username
        );

        $this->assertTrue(
            Hash::check(
                $temporaryPassword,
                $admin->password_hash
            )
        );

        $this->assertNotSame(
            $temporaryPassword,
            $admin->password_hash
        );

        $this->assertTrue(
            (bool) $admin->first_login
        );

        $this->assertTrue(
            (bool) $admin->temporary_password
        );

        $this->assertNotNull(
            $admin->temporary_password_expires_at
        );

        $this->assertNotNull(
            $admin->force_password_reset_at
        );

        $categories = DB::table(
            'teacher_duty_occurrence_categories'
        )
            ->where('school_id', $school->id)
            ->orderBy('display_order')
            ->get();

        $this->assertCount(8, $categories);

        $this->assertSame(
            [
                ['discipline', 10],
                ['attendance', 20],
                ['health_safety', 30],
                ['cleanliness', 40],
                ['property_facilities', 50],
                ['academic', 60],
                ['visitor_security', 70],
                ['general', 80],
            ],
            $categories
                ->map(fn (object $category): array => [
                    $category->code,
                    $category->display_order,
                ])
                ->all()
        );

        foreach ($categories as $category) {
            $this->assertTrue(
                (bool) $category->is_canonical
            );

            $this->assertTrue(
                (bool) $category->active
            );

            $this->assertNull(
                $category->created_by
            );

            $this->assertNull(
                $category->deactivated_by
            );

            $this->assertNull(
                $category->deactivated_at
            );
        }
    }

    public function test_client_cannot_supply_authoritative_school_or_admin_identity_fields(): void
    {
        $user = $this->platformOwner();

        $token = JWTAuth::fromUser(
            $user
        );

        $otherSchoolId = $this->school();

        $this
            ->withToken($token)
            ->postJson(
                '/api/admin/platform/schools',
                [
                    'operating_mode' => 'day_and_boarding',
                    'school_name' => 'Authority Injection School',

                    'school_code' => 'CLIENT-CONTROLLED',

                    'timezone' => 'Africa/Nairobi',

                    'locale' => 'en',

                    'admin' => [
                        'first_name' => 'Initial',

                        'last_name' => 'Administrator',

                        'email' => 'authority-injection-'
                            .Str::lower(
                                Str::random(6)
                            )
                            .'@example.test',

                        'username' => 'platform.owner',

                        'temporary_password' => 'ClientChosen#Password99',

                        'role_id' => $this->platformRoleId,

                        'school_id' => $otherSchoolId,
                    ],
                ]
            )
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'school_code',
                'admin.username',
                'admin.temporary_password',
                'admin.role_id',
                'admin.school_id',
            ]);
    }

    public function test_generated_temporary_password_is_not_persisted_or_audited(): void
    {
        $user = $this->platformOwner();

        $token = JWTAuth::fromUser(
            $user
        );

        $adminEmail = 'credential-security-'
            .Str::lower(
                Str::random(6)
            )
            .'@example.test';

        $response = $this
            ->withToken($token)
            ->postJson(
                '/api/admin/platform/schools',
                [
                    'operating_mode' => 'day_and_boarding',
                    'school_name' => 'Credential Security School',

                    'timezone' => 'Africa/Nairobi',

                    'locale' => 'en',

                    'admin' => [
                        'first_name' => 'Credential',

                        'last_name' => 'Administrator',

                        'email' => $adminEmail,
                    ],
                ]
            )
            ->assertCreated();

        $temporaryPassword = $response->json(
            'data.initial_admin.temporary_password'
        );

        $schoolId = $response->json(
            'data.school.id'
        );

        $admin = DB::table('users')
            ->where(
                'email',
                $adminEmail
            )
            ->first();

        $this->assertNotNull(
            $admin
        );

        $this->assertNotSame(
            $temporaryPassword,
            $admin->password_hash
        );

        $this->assertTrue(
            Hash::check(
                $temporaryPassword,
                $admin->password_hash
            )
        );

        $audit = DB::table('audit_logs')
            ->where(
                'action',
                'platform_school_onboarded'
            )
            ->where(
                'school_id',
                $schoolId
            )
            ->latest(
                'created_at'
            )
            ->first();

        $this->assertNotNull(
            $audit
        );

        $this->assertStringNotContainsString(
            $temporaryPassword,
            json_encode(
                $audit,
                JSON_THROW_ON_ERROR
            )
        );
    }

    public function test_onboarding_rolls_back_school_and_admin_when_school_settings_provisioning_fails(): void
    {
        $user = $this->platformOwner();

        $token = JWTAuth::fromUser(
            $user
        );

        $schoolName = 'Settings Provisioning Rollback School';

        $adminEmail = 'settings-provisioning-rollback-'
            .Str::lower(
                Str::random(6)
            )
            .'@example.test';

        $this->mock(
            SchoolSettingsProvisioningService::class,
            function ($mock) {
                $mock
                    ->shouldReceive('provision')
                    ->once()
                    ->andThrow(
                        new RuntimeException(
                            'Simulated school settings provisioning failure.'
                        )
                    );
            }
        );

        $response = $this
            ->withToken($token)
            ->postJson(
                '/api/admin/platform/schools',
                [
                    'operating_mode' => 'day_and_boarding',
                    'school_name' => $schoolName,
                    'timezone' => 'Africa/Nairobi',
                    'locale' => 'en',

                    'admin' => [
                        'first_name' => 'Settings',
                        'last_name' => 'Rollback',
                        'email' => $adminEmail,
                    ],
                ]
            );

        $response->assertStatus(
            500
        );

        $this->assertDatabaseMissing(
            'schools',
            [
                'operating_mode' => 'day_and_boarding',
                'school_name' => $schoolName,
            ]
        );

        $this->assertDatabaseMissing(
            'users',
            [
                'email' => $adminEmail,
            ]
        );
    }

    public function test_onboarding_rolls_back_school_when_occurrence_category_provisioning_fails(): void
    {
        $user = $this->platformOwner();

        $token = JWTAuth::fromUser(
            $user
        );

        $schoolName = 'Provisioning Rollback School';

        $adminEmail = 'provisioning-rollback-'
            .Str::lower(
                Str::random(6)
            )
            .'@example.test';

        $this->mock(
            TeacherDutyOccurrenceCategoryProvisioningService::class,
            function ($mock) {
                $mock
                    ->shouldReceive('provision')
                    ->once()
                    ->andThrow(
                        new RuntimeException(
                            'Simulated occurrence category provisioning failure.'
                        )
                    );
            }
        );

        $response = $this
            ->withToken($token)
            ->postJson(
                '/api/admin/platform/schools',
                [
                    'operating_mode' => 'day_and_boarding',
                    'school_name' => $schoolName,

                    'timezone' => 'Africa/Nairobi',

                    'locale' => 'en',

                    'admin' => [
                        'first_name' => 'Provisioning',

                        'last_name' => 'Rollback',

                        'email' => $adminEmail,
                    ],
                ]
            );

        $response->assertStatus(
            500
        );

        $this->assertDatabaseMissing(
            'schools',
            [
                'operating_mode' => 'day_and_boarding',
                'school_name' => $schoolName,
            ]
        );

        $this->assertDatabaseMissing(
            'users',
            [
                'email' => $adminEmail,
            ]
        );
    }

    public function test_onboarding_rolls_back_school_and_admin_when_finalization_fails(): void
    {
        $user = $this->platformOwner();

        $token = JWTAuth::fromUser(
            $user
        );

        $adminEmail = 'rollback-admin-'
            .Str::lower(
                Str::random(6)
            )
            .'@example.test';

        $schoolName = 'Atomic Rollback School';

        $this->mock(
            AdministratorAuditService::class,
            function ($mock) {
                $mock
                    ->shouldReceive('record')
                    ->once()
                    ->andThrow(
                        new RuntimeException(
                            'Simulated audit failure.'
                        )
                    );
            }
        );

        $response = $this
            ->withToken($token)
            ->postJson(
                '/api/admin/platform/schools',
                [
                    'operating_mode' => 'day_and_boarding',
                    'school_name' => $schoolName,

                    'timezone' => 'Africa/Nairobi',

                    'locale' => 'en',

                    'admin' => [
                        'first_name' => 'Rollback',

                        'last_name' => 'Administrator',

                        'email' => $adminEmail,
                    ],
                ]
            );

        $response->assertStatus(
            500
        );

        $this->assertDatabaseMissing(
            'schools',
            [
                'operating_mode' => 'day_and_boarding',
                'school_name' => $schoolName,
            ]
        );

        $this->assertDatabaseMissing(
            'users',
            [
                'email' => $adminEmail,
            ]
        );
    }

    public function test_platform_user_with_lifecycle_permission_but_without_onboarding_permission_cannot_onboard_school(): void
    {
        $user = $this->platformOwner();

        $onboardPermissionId = DB::table('permissions')
            ->where(
                'permission_name',
                'onboard_schools'
            )
            ->value('id');

        $lifecyclePermissionId = DB::table('permissions')
            ->where(
                'permission_name',
                'manage_school_lifecycle'
            )
            ->value('id');

        $this->assertNotNull(
            $onboardPermissionId
        );

        $this->assertNotNull(
            $lifecyclePermissionId
        );

        $this->assertTrue(
            DB::table('role_permissions')
                ->where(
                    'role_id',
                    $this->platformRoleId
                )
                ->where(
                    'permission_id',
                    $lifecyclePermissionId
                )
                ->exists()
        );

        DB::table('role_permissions')
            ->where(
                'role_id',
                $this->platformRoleId
            )
            ->where(
                'permission_id',
                $onboardPermissionId
            )
            ->delete();

        $this->assertFalse(
            DB::table('role_permissions')
                ->where(
                    'role_id',
                    $this->platformRoleId
                )
                ->where(
                    'permission_id',
                    $onboardPermissionId
                )
                ->exists()
        );

        $token = JWTAuth::fromUser(
            $user
        );

        $this
            ->withToken($token)
            ->postJson(
                '/api/admin/platform/schools',
                [
                    'operating_mode' => 'day_and_boarding',
                    'school_name' => 'Lifecycle Only School',

                    'timezone' => 'Africa/Nairobi',

                    'locale' => 'en',

                    'admin' => [
                        'first_name' => 'Lifecycle',

                        'last_name' => 'Administrator',

                        'email' => 'lifecycle-only-'
                            .Str::lower(
                                Str::random(6)
                            )
                            .'@example.test',
                    ],
                ]
            )
            ->assertForbidden();
    }

    public function test_school_admin_cannot_onboard_school(): void
    {
        $schoolId = $this->school();

        $user = $this->schoolAdmin(
            $schoolId
        );

        $token = JWTAuth::fromUser(
            $user
        );

        $this
            ->withToken($token)
            ->postJson(
                '/api/admin/platform/schools',
                $this->validPayload()
            )
            ->assertForbidden();
    }

    public function test_unauthenticated_user_cannot_onboard_school(): void
    {
        $this
            ->postJson(
                '/api/admin/platform/schools',
                $this->validPayload()
            )
            ->assertUnauthorized();
    }

    public function test_school_onboarding_route_requires_dedicated_onboarding_permission_and_no_tenant_middleware(): void
    {
        $route = collect(app('router')->getRoutes()->getRoutes())
            ->first(
                fn ($route) => in_array('POST', $route->methods(), true) &&
                    $route->uri() === 'api/admin/platform/schools'
            );

        $this->assertNotNull(
            $route,
            'Expected POST onboarding route [api/admin/platform/schools] was not found.'
        );

        $middleware = $route->gatherMiddleware();

        $this->assertContains(
            'jwt',
            $middleware
        );

        $this->assertContains(
            'permission:access_platform_administration',
            $middleware
        );

        $this->assertContains(
            'permission:onboard_schools',
            $middleware
        );

        $this->assertNotContains(
            'permission:manage_school_lifecycle',
            $middleware
        );

        $this->assertFalse(
            collect($middleware)->contains(
                fn ($name) => str_contains((string) $name, 'tenant') ||
                    str_contains((string) $name, 'school.subscription')
            ),
            'Platform school onboarding must remain outside tenant/subscription middleware.'
        );
    }

    public function test_operating_mode_onboarding_requires_an_explicit_valid_choice(): void
    {
        $owner = $this->platformOwner();
        $this->withToken(JWTAuth::fromUser($owner));
        $schoolsBefore = DB::table('schools')->count();
        $usersBefore = DB::table('users')->count();

        $payload = $this->validPayload();
        unset($payload['operating_mode']);

        $this->postJson('/api/admin/platform/schools', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('operating_mode');

        foreach (['', 'unknown', 'DAY', null] as $mode) {
            $payload['operating_mode'] = $mode;

            $this->postJson('/api/admin/platform/schools', $payload)
                ->assertUnprocessable()
                ->assertJsonValidationErrors('operating_mode');
        }

        $this->assertSame($schoolsBefore, DB::table('schools')->count());
        $this->assertSame($usersBefore, DB::table('users')->count());
    }

    public function test_operating_mode_onboarding_persists_each_supported_choice(): void
    {
        $owner = $this->platformOwner();
        $this->withToken(JWTAuth::fromUser($owner));

        foreach (['day', 'boarding', 'day_and_boarding'] as $mode) {
            $payload = $this->validPayload();
            $payload['operating_mode'] = $mode;

            $response = $this->postJson('/api/admin/platform/schools', $payload)
                ->assertCreated()
                ->assertJsonPath('data.school.operating_mode', $mode);

            $this->assertDatabaseHas('schools', [
                'id' => $response->json('data.school.id'),
                'operating_mode' => $mode,
            ]);
        }
    }

    private function operatingModeAdmin(string $schoolId): User
    {
        MainSubscriptionBuilder::activeFor($schoolId);
        $this->grantPermission($this->schoolAdminRoleId, 'access_administrator_portal');
        $this->grantPermission($this->schoolAdminRoleId, 'manage_school_profile');

        return $this->schoolAdmin($schoolId);
    }

    public function test_existing_school_mode_update_is_audited_and_tenant_scoped(): void
    {
        foreach (['day', 'boarding', 'day_and_boarding'] as $mode) {
            $schoolId = $this->school();
            $foreignId = $this->school();
            DB::table('schools')->where('id', $schoolId)
                ->update(['operating_mode' => null]);

            $foreignBefore = (array) DB::table('schools')->where('id', $foreignId)->first();
            $admin = $this->operatingModeAdmin($schoolId);
            $token = JWTAuth::fromUser($admin);
            JWTAuth::setToken($token);

            $this->withToken($token)->putJson('/api/admin/school', [
                'operating_mode' => $mode,
                'school_id' => $foreignId,
            ])->assertOk()->assertJsonPath('data.operating_mode', $mode);

            $this->assertDatabaseHas('schools', [
                'id' => $schoolId, 'operating_mode' => $mode,
            ]);
            $this->assertDatabaseHas('audit_logs', [
                'school_id' => $schoolId,
                'user_id' => $admin->id,
                'record_id' => $schoolId,
                'action' => 'school_profile_updated',
            ]);
            $this->assertSame(
                $foreignBefore,
                (array) DB::table('schools')->where('id', $foreignId)->first()
            );
        }
    }

    public function test_existing_school_mode_update_rejects_active_incompatible_learners(): void
    {
        foreach ([
            ['boarder', 'day'],
            ['day_scholar', 'boarding'],
        ] as [$learnerMode, $schoolMode]) {
            $schoolId = $this->school();
            $school = (object) ['id' => $schoolId];
            $grade = GradeBuilder::create($school);
            $stream = StreamBuilder::create($school, $grade);
            LearnerBuilder::create($school, $grade, $stream, [
                'mode_of_study' => $learnerMode,
            ]);
            $admin = $this->operatingModeAdmin($schoolId);
            $token = JWTAuth::fromUser($admin);
            JWTAuth::setToken($token);
            $before = (array) DB::table('schools')->where('id', $schoolId)->first();
            $auditBefore = DB::table('audit_logs')->where('school_id', $schoolId)->count();

            $this->withToken($token)->putJson('/api/admin/school', [
                'operating_mode' => $schoolMode,
                'motto' => 'Must roll back with rejected mode',
            ])->assertUnprocessable()->assertJsonValidationErrors('operating_mode');

            $this->assertSame(
                $before,
                (array) DB::table('schools')->where('id', $schoolId)->first()
            );
            $this->assertSame(
                $auditBefore,
                DB::table('audit_logs')->where('school_id', $schoolId)->count()
            );

            $this->putJson('/api/admin/school', [
                'operating_mode' => 'day_and_boarding',
            ])->assertOk();
        }
    }

    public function test_existing_school_mode_update_rolls_back_when_audit_fails(): void
    {
        $schoolId = $this->school();
        $admin = $this->operatingModeAdmin($schoolId);
        $before = (array) DB::table('schools')->where('id', $schoolId)->first();

        $this->mock(AdministratorAuditService::class, function ($mock) {
            $mock->shouldReceive('record')->once()->andThrow(
                new RuntimeException('Operating-mode audit failure')
            );
        });

        $this->withToken(JWTAuth::fromUser($admin))
            ->putJson('/api/admin/school', ['operating_mode' => 'day'])
            ->assertStatus(500);

        $this->assertSame(
            $before,
            (array) DB::table('schools')->where('id', $schoolId)->first()
        );
    }

    public function test_existing_school_mode_update_requires_profile_permission(): void
    {
        $schoolId = $this->school();
        $admin = $this->operatingModeAdmin($schoolId);
        $permissionId = DB::table('permissions')
            ->where('permission_name', 'manage_school_profile')->value('id');

        DB::table('role_permissions')->where('role_id', $this->schoolAdminRoleId)
            ->where('permission_id', $permissionId)->delete();

        $before = (array) DB::table('schools')->where('id', $schoolId)->first();

        $this->withToken(JWTAuth::fromUser($admin))
            ->putJson('/api/admin/school', ['operating_mode' => 'day'])
            ->assertForbidden();

        $this->assertSame(
            $before,
            (array) DB::table('schools')->where('id', $schoolId)->first()
        );
    }

    public function test_invitation_waits_for_commit_and_is_discarded_on_rollback(): void
    {
        \Illuminate\Support\Facades\Mail::fake();
        $owner = $this->platformOwner();
        $level = DB::transactionLevel();
        DB::beginTransaction();

        try {
            $this->withToken(JWTAuth::fromUser($owner))->postJson(
                '/api/admin/platform/schools',
                $this->validPayload(),
            )->assertCreated()->assertJsonPath('data.invitation.status', 'pending');
            \Illuminate\Support\Facades\Mail::assertNothingSent();
        } finally {
            while (DB::transactionLevel() > $level) {
                DB::rollBack();
            }
        }

        \Illuminate\Support\Facades\Mail::assertNothingSent();
    }

    public function test_invitation_is_sent_when_explicit_outer_transaction_commits(): void
    {
        \Illuminate\Support\Facades\Mail::fake();
        $owner = $this->platformOwner();
        $level = DB::transactionLevel();
        DB::beginTransaction();

        try {
            $this->withToken(JWTAuth::fromUser($owner))->postJson(
                '/api/admin/platform/schools',
                $this->validPayload(),
            )->assertCreated()->assertJsonPath('data.invitation.status', 'pending');
            \Illuminate\Support\Facades\Mail::assertNothingSent();
            DB::commit();
            \Illuminate\Support\Facades\Mail::assertSent(\App\Mail\SchoolAdminInvitationMail::class);
            \Illuminate\Support\Facades\Mail::assertSentCount(1);
        } finally {
            while (DB::transactionLevel() > $level) {
                DB::rollBack();
            }
        }
    }

    public function test_reinvitation_rotates_credentials_and_invalidates_old_challenges_without_creating_records(): void
    {
        \Illuminate\Support\Facades\Mail::fake();
        $owner = $this->platformOwner();
        $created = $this->withToken(JWTAuth::fromUser($owner))->postJson(
            '/api/admin/platform/schools', $this->validPayload(),
        )->assertCreated()->json('data');
        $schoolId = $created['school']['id'];
        $userId = $created['initial_admin']['id'];
        $admin = User::findOrFail($userId);
        $generation = $admin->invitation_generation;
        $authGeneration = $admin->auth_generation;
        $oldPassword = $created['initial_admin']['temporary_password'];
        $challenge = app(\App\Services\Auth\SchoolAdminActivationService::class)->begin(
            $admin, \Illuminate\Http\Request::create('/api/auth/login', 'POST'),
        );
        $schools = DB::table('schools')->count();
        $users = DB::table('users')->count();
        $this->travel(25)->hours();

        $response = $this->withToken(JWTAuth::fromUser($owner))->postJson(
            "/api/admin/platform/schools/{$schoolId}/administrators/{$userId}/invitation",
        )->assertOk();
        $response->assertJsonPath('data.school_id', $schoolId)
            ->assertJsonPath('data.user_id', $userId)
            ->assertJsonPath('data.invitation_generation', $generation + 1);
        $this->assertArrayNotHasKey('temporary_password', $response->json('data'));
        $fresh = $admin->fresh();
        $this->assertFalse(Hash::check($oldPassword, $fresh->password_hash));
        $this->assertSame($authGeneration + 1, $fresh->auth_generation);
        $this->assertTrue($fresh->temporary_password_expires_at->isFuture());
        $this->assertNull($fresh->activated_at);
        $this->assertNotNull(DB::table('authentication_challenges')->where('id', $challenge['challenge_id'])->value('consumed_at'));
        $this->assertSame($schools, DB::table('schools')->count());
        $this->assertSame($users, DB::table('users')->count());
        \Illuminate\Support\Facades\Mail::assertSent(\App\Mail\SchoolAdminInvitationMail::class, function ($mail) use ($fresh) {
            return $mail->hasTo($fresh->email) && Hash::check($mail->temporaryPassword, $fresh->password_hash);
        });
    }

    public function test_reinvitation_rejects_activated_accounts_without_resetting_passwords(): void
    {
        \Illuminate\Support\Facades\Mail::fake();
        $owner = $this->platformOwner();
        $created = $this->withToken(JWTAuth::fromUser($owner))->postJson(
            '/api/admin/platform/schools', $this->validPayload(),
        )->assertCreated()->json('data');
        $admin = User::findOrFail($created['initial_admin']['id']);
        $admin->forceFill(['activated_at' => now(), 'first_login' => false, 'temporary_password' => false])->save();
        $hash = $admin->password_hash;
        $this->travel(61)->seconds();
        $this->withToken(JWTAuth::fromUser($owner))->postJson(
            "/api/admin/platform/schools/{$admin->school_id}/administrators/{$admin->id}/invitation",
        )->assertStatus(409);
        $this->assertSame($hash, $admin->fresh()->password_hash);
    }

    public function test_reinvitation_cooldown_and_school_identity_mismatch_do_not_rotate_credentials(): void
    {
        \Illuminate\Support\Facades\Mail::fake();
        $owner = $this->platformOwner();
        $created = $this->withToken(JWTAuth::fromUser($owner))->postJson(
            '/api/admin/platform/schools', $this->validPayload(),
        )->assertCreated()->json('data');
        $admin = User::findOrFail($created['initial_admin']['id']);
        $hash = $admin->password_hash;
        $this->withToken(JWTAuth::fromUser($owner))->postJson(
            "/api/admin/platform/schools/{$admin->school_id}/administrators/{$admin->id}/invitation",
        )->assertStatus(429);
        $other = $this->school();
        $this->travel(61)->seconds();
        $this->withToken(JWTAuth::fromUser($owner))->postJson(
            "/api/admin/platform/schools/{$other}/administrators/{$admin->id}/invitation",
        )->assertNotFound();
        $this->assertSame($hash, $admin->fresh()->password_hash);
    }

    public function test_reinvitation_rejects_anonymous_and_school_actors(): void
    {
        $schoolId = $this->school();
        $admin = $this->schoolAdmin($schoolId);
        $path = "/api/admin/platform/schools/{$schoolId}/administrators/{$admin->id}/invitation";
        $this->postJson($path)->assertUnauthorized();
        $this->withToken(JWTAuth::fromUser($admin))->postJson($path)->assertForbidden();
    }

    public function test_failed_reinvitation_can_be_recovered_without_duplicate_school_or_admin(): void
    {
        \Illuminate\Support\Facades\Mail::fake();
        $owner = $this->platformOwner();
        $created = $this->withToken(JWTAuth::fromUser($owner))->postJson(
            '/api/admin/platform/schools', $this->validPayload(),
        )->assertCreated()->json('data');
        $admin = User::findOrFail($created['initial_admin']['id']);
        $path = "/api/admin/platform/schools/{$admin->school_id}/administrators/{$admin->id}/invitation";
        $schools = DB::table('schools')->count();
        $users = DB::table('users')->count();
        $this->travel(61)->seconds();
        $mock = \Mockery::mock(\App\Services\Platform\SchoolAdminInvitationDelivery::class);
        $mock->shouldReceive('send')->once()->andReturn('failed');
        $this->app->instance(\App\Services\Platform\SchoolAdminInvitationDelivery::class, $mock);
        $this->withToken(JWTAuth::fromUser($owner))->postJson($path)
            ->assertOk()->assertJsonPath('data.invitation.status', 'failed');
        $failedHash = $admin->fresh()->password_hash;
        $this->app->forgetInstance(\App\Services\Platform\SchoolAdminInvitationDelivery::class);
        $this->travel(61)->seconds();
        $this->withToken(JWTAuth::fromUser($owner))->postJson($path)
            ->assertOk()->assertJsonPath('data.invitation.status', 'accepted');
        $this->assertNotSame($failedHash, $admin->fresh()->password_hash);
        $this->assertSame($schools, DB::table('schools')->count());
        $this->assertSame($users, DB::table('users')->count());
    }

    private function validPayload(): array
    {
        return [
            'operating_mode' => 'day_and_boarding',
            'school_name' => 'Unauthorized Onboarding School',

            'timezone' => 'Africa/Nairobi',

            'locale' => 'en',

            'admin' => [
                'first_name' => 'Initial',

                'last_name' => 'Administrator',

                'email' => 'blocked-admin-'
                    .Str::lower(
                        Str::random(6)
                    )
                    .'@example.test',
            ],
        ];
    }

    private function platformOwner(): User
    {
        $this->grantPermission(
            $this->platformRoleId,
            'onboard_schools'
        );

        return User::create([
            'id' => (string) Str::uuid(),

            'school_id' => null,

            'role_id' => $this->platformRoleId,

            'username' => 'platform-owner-'
                .Str::lower(
                    Str::random(6)
                ),

            'password_hash' => Hash::make(
                'Correct#Password99'
            ),

            'email' => 'platform-owner-'
                .Str::lower(
                    Str::random(6)
                )
                .'@example.test',

            'first_name' => 'Platform',

            'last_name' => 'Owner',

            'active' => true,

            'first_login' => false,

            'email_verified_at' => now(),

            'activated_at' => now(),

            'mfa_enabled' => true,

            'auth_generation' => 1,

            'is_deleted' => false,
        ]);
    }

    private function schoolAdmin(
        string $schoolId
    ): User {
        return User::create([
            'id' => (string) Str::uuid(),

            'school_id' => $schoolId,

            'role_id' => $this->schoolAdminRoleId,

            'username' => 'school-admin-'
                .Str::lower(
                    Str::random(6)
                ),

            'password_hash' => Hash::make(
                'Correct#Password99'
            ),

            'email' => 'school-admin-'
                .Str::lower(
                    Str::random(6)
                )
                .'@example.test',

            'first_name' => 'School',

            'last_name' => 'Admin',

            'active' => true,

            'first_login' => false,

            'auth_generation' => 1,

            'is_deleted' => false,
        ]);
    }

    private function school(): string
    {
        $schoolId = (string) Str::uuid();

        DB::table('schools')->insert([
            'id' => $schoolId,

            'operating_mode' => 'day_and_boarding',
            'school_name' => 'Existing Onboarding Test School',

            'school_code' => 'EXIST-'
                .Str::upper(
                    Str::random(5)
                ),

            'active' => true,

            'is_deleted' => false,

            'lifecycle_state' => 'active',

            'timezone' => 'Africa/Nairobi',

            'locale' => 'en',

            'created_at' => now(),

            'updated_at' => now(),
        ]);

        return $schoolId;
    }

    private function grantPermission(
        string $roleId,
        string $permissionName
    ): void {
        $permissionId = DB::table('permissions')
            ->where(
                'permission_name',
                $permissionName
            )
            ->value('id');

        if (! $permissionId) {
            throw new RuntimeException(
                "Required permission [{$permissionName}] was not found."
            );
        }

        DB::table('role_permissions')
            ->insertOrIgnore([
                'id' => (string) Str::uuid(),
                'role_id' => $roleId,
                'permission_id' => $permissionId,
                'created_at' => now(),
            ]);
    }

    private function systemRole(
        string $roleName
    ): string {
        $roleId = DB::table('roles')
            ->where(
                'role_name',
                $roleName
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
                "Required system role [{$roleName}] was not found."
            );
        }

        return (string) $roleId;
    }
}
