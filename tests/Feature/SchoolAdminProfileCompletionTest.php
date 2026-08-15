<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\School;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Tests\TestCase;

class SchoolAdminProfileCompletionTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'jwt.secret' => str_repeat('a', 64),
        ]);
    }

    public function test_activated_school_admin_can_complete_own_school_profile(): void
    {
        [$school, $role] = $this->schoolFixture();

        $user = $this->schoolAdmin(
            $school,
            $role
        );

        $token = JWTAuth::fromUser($user);

        $response = $this
            ->withHeader(
                'Authorization',
                'Bearer '.$token
            )
            ->putJson(
                '/api/admin/school',
                [
                    'school_name' => 'Lakeview Junior School',
                    'short_name' => 'Lakeview',
                    'email' => 'office@lakeview.example.test',
                    'phone' => '+254700000001',
                    'county' => 'Kisumu',
                    'sub_county' => 'Kisumu Central',
                    'postal_address' => 'P.O. Box 100-40100',
                    'physical_address' => 'Kisumu, Kenya',
                    'school_type' => 'Junior School',
                    'ownership' => 'Private',
                    'registration_number' => 'MOE-LAKEVIEW-001',
                    'website' => 'https://lakeview.example.test',
                    'motto' => 'Knowledge and Integrity',
                    'timezone' => 'Africa/Nairobi',
                    'locale' => 'en',
                    'academic_contact' => 'academic@lakeview.example.test',
                    'finance_contact' => 'finance@lakeview.example.test',
                    'communication_contact' => 'communications@lakeview.example.test',
                ]
            );

        $response
            ->assertOk()
            ->assertJsonPath(
                'success',
                true
            )
            ->assertJsonPath(
                'data.school_name',
                'Lakeview Junior School'
            )
            ->assertJsonPath(
                'data.county',
                'Kisumu'
            );

        $school->refresh();

        $this->assertSame(
            'Lakeview Junior School',
            $school->school_name
        );

        $this->assertSame(
            'Kisumu',
            $school->county
        );
    }

    public function test_school_admin_cannot_update_another_school_profile(): void
    {
        [$school, $role] = $this->schoolFixture();
        [$otherSchool] = $this->schoolFixture();

        $user = $this->schoolAdmin(
            $school,
            $role
        );

        $originalOtherSchoolName = $otherSchool->school_name;

        $token = JWTAuth::fromUser($user);

        $response = $this
            ->withHeader(
                'Authorization',
                'Bearer '.$token
            )
            ->putJson(
                '/api/admin/school?school_id='.$otherSchool->id,
                [
                    'school_name' => 'Attempted Tenant Takeover',
                    'county' => 'Nairobi',
                ]
            );

        $response->assertOk();

        $school->refresh();
        $otherSchool->refresh();

        $this->assertSame(
            'Attempted Tenant Takeover',
            $school->school_name
        );

        $this->assertSame(
            $originalOtherSchoolName,
            $otherSchool->school_name
        );
    }

    public function test_school_admin_cannot_mutate_platform_authority_fields(): void
    {
        [$school, $role] = $this->schoolFixture();

        $user = $this->schoolAdmin(
            $school,
            $role
        );

        $originalCode = $school->school_code;
        $originalPrefix = $school->login_prefix;
        $originalLifecycleState = $school->lifecycle_state;
        $originalLifecycleVersion = $school->lifecycle_version;

        $token = JWTAuth::fromUser($user);

        $this
            ->withHeader(
                'Authorization',
                'Bearer '.$token
            )
            ->putJson(
                '/api/admin/school',
                [
                    'school_name' => 'Safe Updated School',
                    'school_code' => 'HACKED',
                    'login_prefix' => 'HACK',
                    'active' => false,
                    'is_deleted' => true,
                    'lifecycle_state' => 'archived',
                    'lifecycle_version' => 999,
                    'suspended_at' => now()->toDateTimeString(),
                    'locked_at' => now()->toDateTimeString(),
                    'archived_at' => now()->toDateTimeString(),
                ]
            )
            ->assertOk();

        $school->refresh();

        $this->assertSame(
            'Safe Updated School',
            $school->school_name
        );

        $this->assertSame(
            $originalCode,
            $school->school_code
        );

        $this->assertSame(
            $originalPrefix,
            $school->login_prefix
        );

        $this->assertTrue(
            (bool) $school->active
        );

        $this->assertFalse(
            (bool) $school->is_deleted
        );

        $this->assertSame(
            $originalLifecycleState,
            $school->lifecycle_state
        );

        $this->assertSame(
            $originalLifecycleVersion,
            $school->lifecycle_version
        );

        $this->assertNull($school->suspended_at);
        $this->assertNull($school->locked_at);
        $this->assertNull($school->archived_at);
    }

    public function test_unauthenticated_user_cannot_update_school_profile(): void
    {
        $this
            ->putJson(
                '/api/admin/school',
                [
                    'school_name' => 'Unauthorized Update',
                ]
            )
            ->assertUnauthorized();
    }

    public function test_platform_owner_cannot_use_school_profile_completion_endpoint(): void
    {
        $role = Role::query()
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
            ->firstOrFail();

        $user = User::create([
            'id' => (string) Str::uuid(),
            'school_id' => null,
            'role_id' => $role->id,
            'username' => 'platform.owner.'.Str::lower(Str::random(8)),
            'password_hash' => Hash::make(
                'PlatformOwnerPermanent123!'
            ),
            'email' => 'platform-owner-'.Str::lower(Str::random(8)).'@example.test',
            'first_name' => 'Platform',
            'last_name' => 'Owner',
            'active' => true,
            'is_deleted' => false,
            'first_login' => false,
            'temporary_password' => false,
            'temporary_password_expires_at' => null,
            'force_password_reset_at' => null,
            'activated_at' => now(),
            'email_verified_at' => now(),
            'auth_generation' => 1,
        ]);

        $token = JWTAuth::fromUser($user);

        $this
            ->withHeader(
                'Authorization',
                'Bearer '.$token
            )
            ->putJson(
                '/api/admin/school',
                [
                    'school_name' => 'Forbidden Update',
                ]
            )
            ->assertForbidden();
    }

    public function test_school_profile_completion_response_does_not_expose_internal_authority_fields(): void
    {
        [$school, $role] = $this->schoolFixture();

        $user = $this->schoolAdmin(
            $school,
            $role
        );

        $token = JWTAuth::fromUser($user);

        $response = $this
            ->withHeader(
                'Authorization',
                'Bearer '.$token
            )
            ->putJson(
                '/api/admin/school',
                [
                    'school_name' => 'Safe Response School',
                ]
            )
            ->assertOk();

        $payload = $response->getContent();

        foreach ([
            'lifecycle_version',
            'login_prefix',
            'is_deleted',
            'deleted_at',
            'deleted_by',
            'suspended_at',
            'locked_at',
            'archived_at',
        ] as $forbiddenField) {
            $this->assertStringNotContainsString(
                '"'.$forbiddenField.'"',
                $payload
            );
        }
    }

    public function test_profile_completion_requires_all_setup_profile_fields(): void
    {
        [$school, $role] = $this->schoolFixture();

        $user = $this->schoolAdmin(
            $school,
            $role
        );

        $token = JWTAuth::fromUser($user);

        $this
            ->withHeader(
                'Authorization',
                'Bearer '.$token
            )
            ->putJson(
                '/api/admin/school/complete-profile',
                [
                    'school_name' => 'Incomplete School',
                ]
            )
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'short_name',
                'registration_number',
                'school_type',
                'county',
                'phone',
                'email',
                'timezone',
                'locale',
            ]);
    }

    public function test_invalid_profile_completion_does_not_partially_update_school(): void
    {
        [$school, $role] = $this->schoolFixture();

        $user = $this->schoolAdmin(
            $school,
            $role
        );

        $originalName = $school->school_name;
        $originalCounty = $school->county;

        $token = JWTAuth::fromUser($user);

        $this
            ->withHeader(
                'Authorization',
                'Bearer '.$token
            )
            ->putJson(
                '/api/admin/school/complete-profile',
                [
                    'school_name' => 'Should Not Persist',
                    'short_name' => 'Invalid',
                    'registration_number' => 'INVALID-001',
                    'school_type' => 'Junior School',
                    'county' => 'Nairobi',
                    'phone' => '+254700000002',
                    'email' => 'not-an-email',
                    'timezone' => 'Africa/Nairobi',
                    'locale' => 'en',
                ]
            )
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'email',
            ]);

        $school->refresh();

        $this->assertSame(
            $originalName,
            $school->school_name
        );

        $this->assertSame(
            $originalCounty,
            $school->county
        );
    }

    public function test_successful_profile_completion_marks_school_profile_setup_step_complete(): void
    {
        [$school, $role] = $this->schoolFixture();

        $user = $this->schoolAdmin(
            $school,
            $role
        );

        $token = JWTAuth::fromUser($user);

        $this
            ->withHeader(
                'Authorization',
                'Bearer '.$token
            )
            ->putJson(
                '/api/admin/school/complete-profile',
                [
                    'school_name' => 'Complete Setup School',
                    'short_name' => 'Complete',
                    'registration_number' => 'COMPLETE-001',
                    'school_type' => 'Junior School',
                    'county' => 'Kisumu',
                    'phone' => '+254700000003',
                    'email' => 'complete@example.test',
                    'timezone' => 'Africa/Nairobi',
                    'locale' => 'en',
                ]
            )
            ->assertOk();

        $this
            ->withHeader(
                'Authorization',
                'Bearer '.$token
            )
            ->getJson(
                '/api/admin/school/setup'
            )
            ->assertOk()
            ->assertJsonPath(
                'data.steps.school_profile',
                true
            );
    }

    public function test_blank_required_profile_value_does_not_complete_school_profile(): void
    {
        [$school, $role] = $this->schoolFixture();

        $user = $this->schoolAdmin(
            $school,
            $role
        );

        $token = JWTAuth::fromUser($user);

        $this
            ->withHeader(
                'Authorization',
                'Bearer '.$token
            )
            ->putJson(
                '/api/admin/school/complete-profile',
                [
                    'school_name' => 'Blank County School',
                    'short_name' => 'Blank County',
                    'registration_number' => 'BLANK-001',
                    'school_type' => 'Junior School',
                    'county' => '',
                    'phone' => '+254700000004',
                    'email' => 'blank@example.test',
                    'timezone' => 'Africa/Nairobi',
                    'locale' => 'en',
                ]
            )
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'county',
            ]);
    }

    private function schoolFixture(): array
    {
        $school = School::create([
            'id' => (string) Str::uuid(),
            'school_name' => 'Profile Setup '.Str::upper(Str::random(4)),
            'school_code' => 'PS'.Str::upper(Str::random(8)),
            'login_prefix' => 'PS',
            'active' => true,
            'is_deleted' => false,
            'lifecycle_state' => 'active',
            'lifecycle_version' => 1,
            'timezone' => 'Africa/Nairobi',
            'locale' => 'en',
        ]);

        $role = Role::query()
            ->where(
                'role_name',
                'School Admin'
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
            ->firstOrFail();

        return [
            $school,
            $role,
        ];
    }

    private function schoolAdmin(
        School $school,
        Role $role
    ): User {
        return User::create([
            'id' => (string) Str::uuid(),
            'school_id' => $school->id,
            'role_id' => $role->id,
            'username' => 'school.admin.'.Str::lower(Str::random(8)),
            'password_hash' => Hash::make(
                'SchoolAdminPermanent123!'
            ),
            'email' => 'school-admin-'.Str::lower(Str::random(8)).'@example.test',
            'first_name' => 'School',
            'last_name' => 'Administrator',
            'active' => true,
            'is_deleted' => false,
            'first_login' => false,
            'temporary_password' => false,
            'temporary_password_expires_at' => null,
            'force_password_reset_at' => null,
            'activated_at' => now(),
            'email_verified_at' => now(),
            'auth_generation' => 1,
        ]);
    }
}
