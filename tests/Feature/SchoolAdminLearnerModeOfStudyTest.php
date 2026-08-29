<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Grade;
use App\Models\Learner;
use App\Models\Role;
use App\Models\School;
use App\Models\Stream;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Tests\TestCase;

class SchoolAdminLearnerModeOfStudyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'jwt.secret' => str_repeat('a', 64),
        ]);
    }

    public function test_school_admin_can_assign_day_scholar_mode_to_unassigned_learner(): void
    {
        [$school, $user] = $this->schoolAdminWithPermission();
        [$grade, $stream] = $this->gradeAndStream($school);
        $learner = $this->learner($school, $grade, $stream);

        $this->assertNull($learner->mode_of_study);

        $this->withToken($this->tokenFor($user))
            ->patchJson(
                "/api/learners/{$learner->id}/mode-of-study",
                [
                    'mode_of_study' => 'day_scholar',
                    'reason' => 'Initial mode assignment.',
                ]
            )
            ->assertSuccessful()
            ->assertJsonPath('data.learner_id', $learner->id)
            ->assertJsonPath('data.mode_of_study', 'day_scholar');

        $this->assertDatabaseHas('learners', [
            'id' => $learner->id,
            'school_id' => $school->id,
            'mode_of_study' => 'day_scholar',
        ]);

        $this->assertDatabaseHas(
            'learner_mode_of_study_history',
            [
                'school_id' => $school->id,
                'learner_id' => $learner->id,
                'from_mode' => null,
                'to_mode' => 'day_scholar',
                'reason' => 'Initial mode assignment.',
                'changed_by' => $user->id,
            ]
        );
    }

    public function test_school_admin_can_assign_boarder_mode_to_unassigned_learner(): void
    {
        [$school, $user] = $this->schoolAdminWithPermission();
        [$grade, $stream] = $this->gradeAndStream($school);
        $learner = $this->learner($school, $grade, $stream);

        $this->withToken($this->tokenFor($user))
            ->patchJson(
                "/api/learners/{$learner->id}/mode-of-study",
                [
                    'mode_of_study' => 'boarder',
                ]
            )
            ->assertSuccessful()
            ->assertJsonPath('data.mode_of_study', 'boarder');

        $this->assertDatabaseHas('learners', [
            'id' => $learner->id,
            'mode_of_study' => 'boarder',
        ]);

        $this->assertDatabaseHas(
            'learner_mode_of_study_history',
            [
                'school_id' => $school->id,
                'learner_id' => $learner->id,
                'from_mode' => null,
                'to_mode' => 'boarder',
                'changed_by' => $user->id,
            ]
        );
    }

    public function test_school_admin_can_change_day_scholar_to_boarder(): void
    {
        [$school, $user] = $this->schoolAdminWithPermission();
        [$grade, $stream] = $this->gradeAndStream($school);

        $learner = $this->learner(
            $school,
            $grade,
            $stream,
            'day_scholar'
        );

        $this->withToken($this->tokenFor($user))
            ->patchJson(
                "/api/learners/{$learner->id}/mode-of-study",
                [
                    'mode_of_study' => 'boarder',
                    'reason' => 'Approved boarding request.',
                ]
            )
            ->assertSuccessful()
            ->assertJsonPath('data.mode_of_study', 'boarder');

        $this->assertDatabaseHas(
            'learner_mode_of_study_history',
            [
                'school_id' => $school->id,
                'learner_id' => $learner->id,
                'from_mode' => 'day_scholar',
                'to_mode' => 'boarder',
                'reason' => 'Approved boarding request.',
                'changed_by' => $user->id,
            ]
        );
    }

    public function test_school_admin_can_change_boarder_to_day_scholar(): void
    {
        [$school, $user] = $this->schoolAdminWithPermission();
        [$grade, $stream] = $this->gradeAndStream($school);

        $learner = $this->learner(
            $school,
            $grade,
            $stream,
            'boarder'
        );

        $this->withToken($this->tokenFor($user))
            ->patchJson(
                "/api/learners/{$learner->id}/mode-of-study",
                [
                    'mode_of_study' => 'day_scholar',
                    'reason' => 'Approved day scholar request.',
                ]
            )
            ->assertSuccessful()
            ->assertJsonPath('data.mode_of_study', 'day_scholar');

        $this->assertDatabaseHas(
            'learner_mode_of_study_history',
            [
                'learner_id' => $learner->id,
                'from_mode' => 'boarder',
                'to_mode' => 'day_scholar',
            ]
        );
    }

    public function test_same_mode_is_rejected_without_history_row(): void
    {
        [$school, $user] = $this->schoolAdminWithPermission();
        [$grade, $stream] = $this->gradeAndStream($school);

        $learner = $this->learner(
            $school,
            $grade,
            $stream,
            'day_scholar'
        );

        $this->withToken($this->tokenFor($user))
            ->patchJson(
                "/api/learners/{$learner->id}/mode-of-study",
                [
                    'mode_of_study' => 'day_scholar',
                ]
            )
            ->assertStatus(422)
            ->assertJsonValidationErrors('mode_of_study');

        $this->assertDatabaseMissing(
            'learner_mode_of_study_history',
            [
                'learner_id' => $learner->id,
            ]
        );
    }

    public function test_invalid_mode_is_rejected(): void
    {
        [$school, $user] = $this->schoolAdminWithPermission();
        [$grade, $stream] = $this->gradeAndStream($school);
        $learner = $this->learner($school, $grade, $stream);

        $this->withToken($this->tokenFor($user))
            ->patchJson(
                "/api/learners/{$learner->id}/mode-of-study",
                [
                    'mode_of_study' => 'day_with_transport',
                ]
            )
            ->assertStatus(422)
            ->assertJsonValidationErrors('mode_of_study');

        $this->assertDatabaseHas('learners', [
            'id' => $learner->id,
            'mode_of_study' => null,
        ]);
    }

    public function test_mode_is_required(): void
    {
        [$school, $user] = $this->schoolAdminWithPermission();
        [$grade, $stream] = $this->gradeAndStream($school);
        $learner = $this->learner($school, $grade, $stream);

        $this->withToken($this->tokenFor($user))
            ->patchJson(
                "/api/learners/{$learner->id}/mode-of-study",
                []
            )
            ->assertStatus(422)
            ->assertJsonValidationErrors('mode_of_study');
    }

    public function test_reason_is_limited_to_500_characters(): void
    {
        [$school, $user] = $this->schoolAdminWithPermission();
        [$grade, $stream] = $this->gradeAndStream($school);
        $learner = $this->learner($school, $grade, $stream);

        $this->withToken($this->tokenFor($user))
            ->patchJson(
                "/api/learners/{$learner->id}/mode-of-study",
                [
                    'mode_of_study' => 'boarder',
                    'reason' => str_repeat('x', 501),
                ]
            )
            ->assertStatus(422)
            ->assertJsonValidationErrors('reason');

        $this->assertDatabaseMissing(
            'learner_mode_of_study_history',
            [
                'learner_id' => $learner->id,
            ]
        );
    }

    public function test_foreign_learner_mode_cannot_be_changed(): void
    {
        [$school, $user] = $this->schoolAdminWithPermission();

        $foreignSchool = $this->school();
        [$grade, $stream] =
            $this->gradeAndStream($foreignSchool);

        $foreignLearner = $this->learner(
            $foreignSchool,
            $grade,
            $stream
        );

        $this->withToken($this->tokenFor($user))
            ->patchJson(
                "/api/learners/{$foreignLearner->id}/mode-of-study",
                [
                    'mode_of_study' => 'boarder',
                ]
            )
            ->assertNotFound();

        $this->assertDatabaseMissing(
            'learner_mode_of_study_history',
            [
                'school_id' => $school->id,
                'learner_id' => $foreignLearner->id,
            ]
        );
    }

    public function test_inactive_learner_mode_cannot_be_changed(): void
    {
        [$school, $user] = $this->schoolAdminWithPermission();
        [$grade, $stream] = $this->gradeAndStream($school);
        $learner = $this->learner($school, $grade, $stream);

        $learner->update([
            'active' => false,
        ]);

        $this->withToken($this->tokenFor($user))
            ->patchJson(
                "/api/learners/{$learner->id}/mode-of-study",
                [
                    'mode_of_study' => 'boarder',
                ]
            )
            ->assertStatus(422)
            ->assertJsonValidationErrors('learner');

        $this->assertDatabaseMissing(
            'learner_mode_of_study_history',
            [
                'learner_id' => $learner->id,
            ]
        );
    }

    public function test_deleted_learner_mode_cannot_be_changed(): void
    {
        [$school, $user] = $this->schoolAdminWithPermission();
        [$grade, $stream] = $this->gradeAndStream($school);
        $learner = $this->learner($school, $grade, $stream);

        DB::table('learners')
            ->where('id', $learner->id)
            ->update([
                'is_deleted' => true,
                'deleted_at' => now(),
                'deleted_by' => $user->id,
            ]);

        $this->withToken($this->tokenFor($user))
            ->patchJson(
                "/api/learners/{$learner->id}/mode-of-study",
                [
                    'mode_of_study' => 'boarder',
                ]
            )
            ->assertNotFound();
    }

    public function test_school_admin_can_view_current_mode(): void
    {
        [$school, $user] = $this->schoolAdminWithPermission();
        [$grade, $stream] = $this->gradeAndStream($school);

        $learner = $this->learner(
            $school,
            $grade,
            $stream,
            'boarder'
        );

        $this->withToken($this->tokenFor($user))
            ->getJson(
                "/api/learners/{$learner->id}/mode-of-study"
            )
            ->assertSuccessful()
            ->assertJsonPath('data.learner_id', $learner->id)
            ->assertJsonPath('data.mode_of_study', 'boarder');
    }

    public function test_foreign_learner_current_mode_cannot_be_viewed(): void
    {
        [$school, $user] = $this->schoolAdminWithPermission();

        $foreignSchool = $this->school();
        [$grade, $stream] =
            $this->gradeAndStream($foreignSchool);

        $learner = $this->learner(
            $foreignSchool,
            $grade,
            $stream,
            'boarder'
        );

        $this->withToken($this->tokenFor($user))
            ->getJson(
                "/api/learners/{$learner->id}/mode-of-study"
            )
            ->assertNotFound();
    }

    public function test_mode_history_is_tenant_scoped_and_newest_first(): void
    {
        /*
         * Build fixtures for both tenants before making any authenticated
         * request. Tenant-aware models must not inherit request tenant state
         * while cross-tenant fixtures are being prepared.
         */
        [$school, $user] = $this->schoolAdminWithPermission();

        [$grade, $stream] = $this->gradeAndStream($school);

        $learner = $this->learner(
            $school,
            $grade,
            $stream
        );

        $foreignSchool = $this->school();
        $foreignUser = $this->user($foreignSchool);

        $permissionId = DB::table('permissions')
            ->where('permission_name', 'manage_learners')
            ->value('id');

        $this->assertNotNull($permissionId);

        DB::table('role_permissions')->insert([
            'id' => (string) Str::uuid(),
            'role_id' => $foreignUser->role_id,
            'permission_id' => $permissionId,
            'created_at' => now(),
        ]);

        [$foreignGrade, $foreignStream] =
            $this->gradeAndStream(
                $foreignSchool,
                'Grade 8',
                'North'
            );

        $foreignLearner = $this->learner(
            $foreignSchool,
            $foreignGrade,
            $foreignStream
        );

        $this->withToken($this->tokenFor($user))
            ->patchJson(
                "/api/learners/{$learner->id}/mode-of-study",
                [
                    'mode_of_study' => 'day_scholar',
                    'reason' => 'Initial assignment.',
                ]
            )
            ->assertSuccessful();

        $this->withToken($this->tokenFor($user))
            ->patchJson(
                "/api/learners/{$learner->id}/mode-of-study",
                [
                    'mode_of_study' => 'boarder',
                    'reason' => 'Boarding approved.',
                ]
            )
            ->assertSuccessful();

        $this->withToken($this->tokenFor($foreignUser))
            ->patchJson(
                "/api/learners/{$foreignLearner->id}/mode-of-study",
                [
                    'mode_of_study' => 'boarder',
                ]
            )
            ->assertSuccessful();

        $response = $this->withToken($this->tokenFor($user))
            ->getJson(
                "/api/learners/{$learner->id}/mode-of-study/history"
            )
            ->assertSuccessful()
            ->assertJsonCount(2, 'data');

        $this->assertSame(
            'boarder',
            $response->json('data.0.to_mode')
        );

        $this->assertSame(
            'day_scholar',
            $response->json('data.1.to_mode')
        );

        foreach ($response->json('data') as $change) {
            $this->assertSame(
                $learner->id,
                $change['learner_id']
            );

            $this->assertNotSame(
                $foreignLearner->id,
                $change['learner_id']
            );
        }
    }

    public function test_foreign_learner_mode_history_cannot_be_viewed(): void
    {
        [$school, $user] = $this->schoolAdminWithPermission();

        $foreignSchool = $this->school();
        [$grade, $stream] =
            $this->gradeAndStream($foreignSchool);

        $foreignLearner = $this->learner(
            $foreignSchool,
            $grade,
            $stream
        );

        $this->withToken($this->tokenFor($user))
            ->getJson(
                "/api/learners/{$foreignLearner->id}/mode-of-study/history"
            )
            ->assertNotFound();
    }

    public function test_generic_learner_update_cannot_change_mode_of_study(): void
    {
        [$school, $user] = $this->schoolAdminWithPermission();
        [$grade, $stream] = $this->gradeAndStream($school);

        $learner = $this->learner(
            $school,
            $grade,
            $stream,
            'day_scholar'
        );

        $this->withToken($this->tokenFor($user))
            ->putJson(
                "/api/learners/{$learner->id}",
                [
                    'mode_of_study' => 'boarder',
                ]
            )
            ->assertStatus(422)
            ->assertJsonValidationErrors('mode_of_study');

        $this->assertDatabaseHas('learners', [
            'id' => $learner->id,
            'mode_of_study' => 'day_scholar',
        ]);

        $this->assertDatabaseMissing(
            'learner_mode_of_study_history',
            [
                'learner_id' => $learner->id,
            ]
        );
    }

    public function test_client_cannot_override_server_controlled_history_fields(): void
    {
        [$school, $user] = $this->schoolAdminWithPermission();
        [$grade, $stream] = $this->gradeAndStream($school);
        $learner = $this->learner($school, $grade, $stream);

        $foreignSchool = $this->school();
        $foreignUser = $this->user($foreignSchool);

        $fakeTime = '2001-01-01 00:00:00';

        $this->withToken($this->tokenFor($user))
            ->patchJson(
                "/api/learners/{$learner->id}/mode-of-study",
                [
                    'mode_of_study' => 'boarder',
                    'reason' => 'Valid reason.',
                    'school_id' => $foreignSchool->id,
                    'learner_id' => (string) Str::uuid(),
                    'from_mode' => 'day_scholar',
                    'to_mode' => 'day_scholar',
                    'changed_by' => $foreignUser->id,
                    'changed_at' => $fakeTime,
                    'created_at' => $fakeTime,
                ]
            )
            ->assertSuccessful();

        $history = DB::table(
            'learner_mode_of_study_history'
        )
            ->where('learner_id', $learner->id)
            ->first();

        $this->assertNotNull($history);
        $this->assertSame($school->id, $history->school_id);
        $this->assertSame($learner->id, $history->learner_id);
        $this->assertNull($history->from_mode);
        $this->assertSame('boarder', $history->to_mode);
        $this->assertSame($user->id, $history->changed_by);
        $this->assertNotSame($fakeTime, (string) $history->changed_at);
    }

    public function test_mode_change_is_audited(): void
    {
        [$school, $user] = $this->schoolAdminWithPermission();
        [$grade, $stream] = $this->gradeAndStream($school);
        $learner = $this->learner($school, $grade, $stream);

        $this->withToken($this->tokenFor($user))
            ->patchJson(
                "/api/learners/{$learner->id}/mode-of-study",
                [
                    'mode_of_study' => 'boarder',
                ]
            )
            ->assertSuccessful();

        $audit = DB::table('audit_logs')
            ->where('school_id', $school->id)
            ->where('user_id', $user->id)
            ->where('module', 'Learner Mode of Study')
            ->where('action', 'Change')
            ->where('table_name', 'learners')
            ->where('record_id', $learner->id)
            ->first();

        $this->assertNotNull($audit);
    }

    public function test_mode_routes_require_manage_learners_permission(): void
    {
        $routes = collect(
            app('router')->getRoutes()->getRoutes()
        );

        foreach ([
            ['GET', 'api/learners/{learner}/mode-of-study'],
            ['PATCH', 'api/learners/{learner}/mode-of-study'],
            ['GET', 'api/learners/{learner}/mode-of-study/history'],
        ] as [$method, $uri]) {
            $route = $routes->first(
                fn ($route) => in_array(
                    $method,
                    $route->methods(),
                    true
                )
                    && $route->uri() === $uri
            );

            $this->assertNotNull(
                $route,
                "{$method} {$uri} route is missing."
            );

            $this->assertContains(
                'permission:manage_learners',
                $route->gatherMiddleware(),
                "{$method} {$uri} must require manage_learners."
            );
        }
    }

    public function test_mode_routes_do_not_require_operational_setup_gate(): void
    {
        $routes = collect(
            app('router')->getRoutes()->getRoutes()
        );

        foreach ([
            ['GET', 'api/learners/{learner}/mode-of-study'],
            ['PATCH', 'api/learners/{learner}/mode-of-study'],
            ['GET', 'api/learners/{learner}/mode-of-study/history'],
        ] as [$method, $uri]) {
            $route = $routes->first(
                fn ($route) => in_array(
                    $method,
                    $route->methods(),
                    true
                )
                    && $route->uri() === $uri
            );

            $this->assertNotNull($route);

            $this->assertNotContains(
                'school.operational',
                $route->gatherMiddleware()
            );
        }
    }

    public function test_unauthenticated_user_cannot_change_mode(): void
    {
        $school = $this->school();
        [$grade, $stream] = $this->gradeAndStream($school);
        $learner = $this->learner($school, $grade, $stream);

        $this->patchJson(
            "/api/learners/{$learner->id}/mode-of-study",
            [
                'mode_of_study' => 'boarder',
            ]
        )
            ->assertUnauthorized();
    }

    public function test_user_without_manage_learners_permission_cannot_change_mode(): void
    {
        $school = $this->school();
        $user = $this->user($school);

        [$grade, $stream] = $this->gradeAndStream($school);
        $learner = $this->learner($school, $grade, $stream);

        $this->withToken($this->tokenFor($user))
            ->patchJson(
                "/api/learners/{$learner->id}/mode-of-study",
                [
                    'mode_of_study' => 'boarder',
                ]
            )
            ->assertStatus(403);

        $this->assertDatabaseHas('learners', [
            'id' => $learner->id,
            'mode_of_study' => null,
        ]);
    }

    public function test_database_rejects_invalid_current_mode(): void
    {
        [$school] = $this->schoolAdminWithPermission();
        [$grade, $stream] = $this->gradeAndStream($school);
        $learner = $this->learner($school, $grade, $stream);

        $this->expectException(\Throwable::class);

        DB::table('learners')
            ->where('id', $learner->id)
            ->update([
                'mode_of_study' => 'invalid_mode',
            ]);
    }

    private function schoolAdminWithPermission(): array
    {
        $school = $this->school();
        $user = $this->user($school);

        $permissionId = DB::table('permissions')
            ->where(
                'permission_name',
                'manage_learners'
            )
            ->value('id');

        $this->assertNotNull(
            $permissionId,
            'manage_learners must be provisioned.'
        );

        DB::table('role_permissions')
            ->insert([
                'id' => (string) Str::uuid(),
                'role_id' => $user->role_id,
                'permission_id' => $permissionId,
                'created_at' => now(),
            ]);

        return [$school, $user];
    }

    private function school(): School
    {
        return School::query()->create([
            'id' => (string) Str::uuid(),
            'school_name' => 'School '.Str::upper(
                Str::random(8)
            ),
            'school_code' => 'LRN-'.Str::upper(
                Str::random(8)
            ),
            'short_name' => 'LRN',
            'registration_number' => 'REG-'.Str::upper(Str::random(10)),
            'school_type' => 'Primary',
            'county' => 'Nairobi',
            'phone' => '+2547'.random_int(
                10000000,
                99999999
            ),
            'email' => Str::lower(Str::random(10)).
                '@example.test',
            'timezone' => 'Africa/Nairobi',
            'locale' => 'en',
            'active' => true,
        ]);
    }

    private function user(School $school): User
    {
        $role = Role::query()->create([
            'id' => (string) Str::uuid(),
            'role_name' => 'School Admin '.
                Str::upper(Str::random(8)),
            'description' => 'Test school administrator',
            'active' => true,
        ]);

        return User::query()->create([
            'id' => (string) Str::uuid(),
            'school_id' => $school->id,
            'role_id' => $role->id,
            'first_name' => 'Test',
            'last_name' => 'Admin',
            'username' => 'admin_'.
                Str::lower(Str::random(10)),
            'email' => Str::lower(Str::random(10)).
                '@example.test',
            'password_hash' => bcrypt('Password123!'),
            'active' => true,
            'first_login' => false,
        ]);
    }

    private function gradeAndStream(
        School $school,
        string $gradeName = 'Grade 7',
        string $streamName = 'East'
    ): array {
        $grade = Grade::query()
            ->withoutGlobalScopes()
            ->create([
                'id' => (string) Str::uuid(),
                'school_id' => $school->id,
                'grade_name' => $gradeName,
                'grade_order' => random_int(1, 1000),
                'active' => true,
            ]);

        $stream = Stream::query()
            ->withoutGlobalScopes()
            ->create([
                'id' => (string) Str::uuid(),
                'school_id' => $school->id,
                'grade_id' => $grade->id,
                'stream_name' => $streamName,
                'active' => true,
                'created_at' => now(),
            ]);

        return [$grade, $stream];
    }

    private function learner(
        School $school,
        Grade $grade,
        Stream $stream,
        ?string $modeOfStudy = null
    ): Learner {
        $learner = Learner::query()
            ->withoutGlobalScopes()
            ->create([
                'id' => (string) Str::uuid(),
                'school_id' => $school->id,
                'admission_no' => 'ADM-MODE-'.
                    Str::upper(Str::random(8)),
                'first_name' => 'Existing',
                'last_name' => 'Learner',
                'grade_id' => $grade->id,
                'stream_id' => $stream->id,
                'admission_date' => now()->toDateString(),
                'active' => true,
                'is_deleted' => false,
                'portal_enabled' => false,
            ]);

        if ($modeOfStudy !== null) {
            $learner->mode_of_study = $modeOfStudy;
            $learner->save();
            $learner->refresh();
        }

        return $learner;
    }

    private function tokenFor(User $user): string
    {
        return JWTAuth::fromUser(
            User::query()
                ->withoutGlobalScopes()
                ->findOrFail($user->id)
        );
    }
}
