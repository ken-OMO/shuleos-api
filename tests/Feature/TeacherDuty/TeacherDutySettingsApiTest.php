<?php

declare(strict_types=1);

namespace Tests\Feature\TeacherDuty;

use App\Models\Role;
use App\Models\School;
use App\Models\User;
use App\Services\School\SchoolSettingsProvisioningService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Tests\TestCase;

final class TeacherDutySettingsApiTest extends TestCase
{
    use DatabaseTransactions;

    private School $school;

    private User $administrator;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'jwt.secret' => str_repeat('a', 64),
        ]);

        $this->school = $this->createSchool();
        $this->completeOperationalSetup($this->school);
        $this->administrator = $this->createUser($this->school);
        $this->grantPermission(
            $this->administrator,
            'manage_teacher_duty_roster'
        );
    }

    public function test_settings_routes_have_frozen_security_contract(): void
    {
        $expected = [
            [
                'GET',
                'api/teacher-duty/settings',
                false,
            ],
            [
                'PUT',
                'api/teacher-duty/settings',
                true,
            ],
        ];

        foreach ($expected as [$method, $uri, $operational]) {
            $route = collect(Route::getRoutes()->getRoutes())
                ->first(
                    fn ($route): bool => in_array($method, $route->methods(), true)
                        && $route->uri() === $uri
                );

            $this->assertNotNull(
                $route,
                "{$method} {$uri} route is missing."
            );

            $middleware = $route->gatherMiddleware();

            $this->assertContains(
                'permission:manage_teacher_duty_roster',
                $middleware,
                "{$method} {$uri} must require Teacher Duty administration."
            );

            if ($operational) {
                $this->assertContains(
                    'school.operational',
                    $middleware,
                    "{$method} {$uri} must require an operational school."
                );
            } else {
                $this->assertNotContains(
                    'school.operational',
                    $middleware,
                    "{$method} {$uri} must remain readable outside the operational write boundary."
                );
            }
        }
    }

    public function test_administrator_can_read_only_teacher_duty_settings(): void
    {
        app(SchoolSettingsProvisioningService::class)
            ->provision($this->school);

        DB::table('school_settings')
            ->where('school_id', $this->school->id)
            ->update([
                'teacher_duty_report_deadline_time' => '18:30:00',
                'teacher_duty_report_grace_minutes' => 45,
                'parent_portal_enabled' => true,
            ]);

        $this->actingAsJwt($this->administrator);

        $response = $this->getJson('/api/teacher-duty/settings');

        $response
            ->assertOk()
            ->assertExactJson([
                'data' => [
                    'teacher_duty_report_deadline_time' => '18:30:00',
                    'teacher_duty_report_grace_minutes' => 45,
                ],
            ]);
    }

    public function test_administrator_can_update_teacher_duty_settings(): void
    {
        app(SchoolSettingsProvisioningService::class)
            ->provision($this->school);

        $this->actingAsJwt($this->administrator);

        $response = $this->putJson(
            '/api/teacher-duty/settings',
            [
                'teacher_duty_report_deadline_time' => '18:45',
                'teacher_duty_report_grace_minutes' => 30,
            ]
        );

        $response
            ->assertOk()
            ->assertExactJson([
                'message' => 'Teacher duty settings updated successfully.',
                'data' => [
                    'teacher_duty_report_deadline_time' => '18:45:00',
                    'teacher_duty_report_grace_minutes' => 30,
                ],
            ]);

        $this->assertDatabaseHas('school_settings', [
            'school_id' => $this->school->id,
            'teacher_duty_report_deadline_time' => '18:45:00',
            'teacher_duty_report_grace_minutes' => 30,
        ]);
    }

    private function actingAsJwt(User $user): void
    {
        $token = JWTAuth::fromUser($user);

        $this->withHeaders([
            'Authorization' => "Bearer {$token}",
        ]);
    }

    public function test_update_rejects_deadline_outside_frozen_hour_minute_format(): void
    {
        $this->actingAsJwt($this->administrator);

        $response = $this->putJson(
            '/api/teacher-duty/settings',
            [
                'teacher_duty_report_deadline_time' => '18:45:00',
                'teacher_duty_report_grace_minutes' => 30,
            ]
        );

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'teacher_duty_report_deadline_time',
            ]);
    }

    public function test_update_rejects_negative_grace_minutes(): void
    {
        $this->actingAsJwt($this->administrator);

        $response = $this->putJson(
            '/api/teacher-duty/settings',
            [
                'teacher_duty_report_deadline_time' => '18:45',
                'teacher_duty_report_grace_minutes' => -1,
            ]
        );

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'teacher_duty_report_grace_minutes',
            ]);
    }

    public function test_user_without_manage_roster_permission_cannot_read_or_update_settings(): void
    {
        $unauthorized = $this->createUser($this->school);

        $this->actingAsJwt($unauthorized);

        $this->getJson('/api/teacher-duty/settings')
            ->assertForbidden();

        $this->actingAsJwt($unauthorized);

        $this->putJson(
            '/api/teacher-duty/settings',
            [
                'teacher_duty_report_deadline_time' => '19:00',
                'teacher_duty_report_grace_minutes' => 15,
            ]
        )->assertForbidden();
    }

    public function test_update_rejects_client_supplied_school_id(): void
    {
        $this->actingAsJwt($this->administrator);

        $response = $this->putJson(
            '/api/teacher-duty/settings',
            [
                'school_id' => $this->school->id,
                'teacher_duty_report_deadline_time' => '19:00',
                'teacher_duty_report_grace_minutes' => 15,
            ]
        );

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['school_id']);
    }

    public function test_update_does_not_mutate_unrelated_school_settings(): void
    {
        app(SchoolSettingsProvisioningService::class)
            ->provision($this->school);

        DB::table('school_settings')
            ->where('school_id', $this->school->id)
            ->update([
                'parent_portal_enabled' => true,
            ]);

        $this->actingAsJwt($this->administrator);

        $this->putJson(
            '/api/teacher-duty/settings',
            [
                'teacher_duty_report_deadline_time' => '19:15',
                'teacher_duty_report_grace_minutes' => 25,
                'parent_portal_enabled' => false,
            ]
        )->assertOk();

        $this->assertDatabaseHas('school_settings', [
            'school_id' => $this->school->id,
            'teacher_duty_report_deadline_time' => '19:15:00',
            'teacher_duty_report_grace_minutes' => 25,
            'parent_portal_enabled' => true,
        ]);
    }

    public function test_get_provisions_missing_settings_with_authoritative_defaults(): void
    {
        $this->assertDatabaseMissing('school_settings', [
            'school_id' => $this->school->id,
        ]);

        $this->actingAsJwt($this->administrator);

        $response = $this->getJson('/api/teacher-duty/settings');

        $response
            ->assertOk()
            ->assertExactJson([
                'data' => [
                    'teacher_duty_report_deadline_time' => '17:00:00',
                    'teacher_duty_report_grace_minutes' => 120,
                ],
            ]);

        $this->assertDatabaseHas('school_settings', [
            'school_id' => $this->school->id,
            'teacher_duty_report_deadline_time' => '17:00:00',
            'teacher_duty_report_grace_minutes' => 120,
        ]);
    }

    public function test_update_is_audited_against_school_settings(): void
    {
        app(SchoolSettingsProvisioningService::class)
            ->provision($this->school);

        $settingsId = DB::table('school_settings')
            ->where('school_id', $this->school->id)
            ->value('id');

        $this->assertNotNull($settingsId);

        $this->actingAsJwt($this->administrator);

        $this->putJson(
            '/api/teacher-duty/settings',
            [
                'teacher_duty_report_deadline_time' => '19:30',
                'teacher_duty_report_grace_minutes' => 35,
            ]
        )->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'school_id' => $this->school->id,
            'user_id' => $this->administrator->id,
            'module' => 'Teacher Duty Settings',
            'action' => 'Update',
            'table_name' => 'school_settings',
            'record_id' => $settingsId,
            'description' => 'Updated teacher duty settings.',
        ]);
    }

    public function test_reviewer_permission_alone_cannot_read_or_update_teacher_duty_settings(): void
    {
        $reviewer = $this->createUser($this->school);

        $this->grantPermission(
            $reviewer,
            'review_teacher_duty_reports'
        );

        $this->actingAsJwt($reviewer);

        $this->getJson('/api/teacher-duty/settings')
            ->assertForbidden();

        $this->actingAsJwt($reviewer);

        $this->putJson(
            '/api/teacher-duty/settings',
            [
                'teacher_duty_report_deadline_time' => '20:00',
                'teacher_duty_report_grace_minutes' => 20,
            ]
        )->assertForbidden();
    }

    private function createSchool(): School
    {
        return School::query()->create([
            'id' => (string) Str::uuid(),
            'school_name' => 'Teacher Duty Settings '.Str::upper(
                Str::random(8)
            ),
            'school_code' => 'TDS-'.Str::upper(Str::random(8)),
            'short_name' => 'TDS',
            'registration_number' => 'REG-'.Str::upper(
                Str::random(10)
            ),
            'school_type' => 'Primary',
            'county' => 'Nairobi',
            'phone' => '+2547'.random_int(10000000, 99999999),
            'email' => Str::lower(Str::random(10)).'@example.test',
            'timezone' => 'Africa/Nairobi',
            'locale' => 'en',
            'active' => true,
        ]);
    }

    private function completeOperationalSetup(School $school): void
    {
        $academicYearId = (string) Str::uuid();
        $gradeId = (string) Str::uuid();

        DB::table('academic_years')->insert([
            'id' => $academicYearId,
            'school_id' => $school->id,
            'year_name' => 'Operational '.Str::upper(
                Str::random(8)
            ),
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'active' => true,
            'created_at' => now(),
        ]);

        DB::table('terms')->insert([
            'id' => (string) Str::uuid(),
            'school_id' => $school->id,
            'academic_year_id' => $academicYearId,
            'term_name' => 'Operational '.Str::upper(
                Str::random(6)
            ),
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'active' => true,
            'created_at' => now(),
        ]);

        DB::table('grades')->insert([
            'id' => $gradeId,
            'school_id' => $school->id,
            'grade_name' => 'Readiness '.Str::upper(
                Str::random(8)
            ),
            'grade_order' => random_int(3001, 4000),
            'active' => true,
            'created_at' => now(),
        ]);

        DB::table('streams')->insert([
            'id' => (string) Str::uuid(),
            'school_id' => $school->id,
            'grade_id' => $gradeId,
            'stream_name' => 'Readiness '.Str::upper(
                Str::random(8)
            ),
            'active' => true,
            'created_at' => now(),
        ]);
    }

    private function createUser(School $school): User
    {
        $role = Role::query()->create([
            'id' => (string) Str::uuid(),
            'role_name' => 'Teacher Duty Settings '.Str::upper(
                Str::random(8)
            ),
            'description' => 'Teacher duty settings API test role',
            'active' => true,
        ]);

        return User::query()->create([
            'id' => (string) Str::uuid(),
            'school_id' => $school->id,
            'role_id' => $role->id,
            'first_name' => 'Teacher Duty',
            'last_name' => 'Administrator',
            'username' => 'teacher_duty_settings_'.Str::lower(
                Str::random(10)
            ),
            'email' => Str::lower(Str::random(10)).'@example.test',
            'password_hash' => bcrypt('Password123!'),
            'active' => true,
            'first_login' => false,
        ]);
    }

    private function grantPermission(
        User $user,
        string $permissionName
    ): void {
        $permissionId = DB::table('permissions')
            ->where('permission_name', $permissionName)
            ->value('id');

        if (! $permissionId) {
            throw new \RuntimeException(
                "Required migrated permission [{$permissionName}] was not found."
            );
        }

        if (! $user->role_id) {
            throw new \RuntimeException(
                'Teacher Duty settings API test actor has no primary role.'
            );
        }

        DB::table('role_permissions')->insertOrIgnore([
            'id' => (string) Str::uuid(),
            'role_id' => $user->role_id,
            'permission_id' => $permissionId,
            'created_at' => now(),
        ]);
    }
}
