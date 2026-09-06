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

class SchoolAdminLearnerPlacementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'jwt.secret' => str_repeat('a', 64),
        ]);
    }

    public function test_school_admin_can_change_learner_stream_within_same_grade(): void
    {
        [$school, $user] = $this->schoolAdminWithPermission();

        [$grade, $east] = $this->gradeAndStream(
            $school,
            'Grade 7',
            'East'
        );

        $west = $this->stream(
            $school,
            $grade,
            'West'
        );

        $learner = $this->learner(
            $school,
            $grade,
            $east
        );

        $this->withToken($this->tokenFor($user))
            ->postJson(
                "/api/learners/{$learner->id}/placements",
                [
                    'grade_id' => $grade->id,
                    'stream_id' => $west->id,
                    'reason' => 'Class balancing.',
                ]
            )
            ->assertSuccessful()
            ->assertJsonPath(
                'data.learner_id',
                $learner->id
            )
            ->assertJsonPath(
                'data.grade_id',
                $grade->id
            )
            ->assertJsonPath(
                'data.stream_id',
                $west->id
            )
            ->assertJsonPath(
                'data.placement.placement_type',
                'stream_change'
            );

        $this->assertDatabaseHas('learners', [
            'id' => $learner->id,
            'school_id' => $school->id,
            'grade_id' => $grade->id,
            'stream_id' => $west->id,
        ]);

        $this->assertDatabaseHas(
            'learner_placement_history',
            [
                'school_id' => $school->id,
                'learner_id' => $learner->id,
                'from_grade_id' => $grade->id,
                'from_stream_id' => $east->id,
                'to_grade_id' => $grade->id,
                'to_stream_id' => $west->id,
                'placement_type' => 'stream_change',
                'reason' => 'Class balancing.',
                'placed_by' => $user->id,
            ]
        );
    }

    public function test_school_admin_can_change_learner_grade_and_stream(): void
    {
        [$school, $user] = $this->schoolAdminWithPermission();

        [$gradeSeven, $east] = $this->gradeAndStream(
            $school,
            'Grade 7',
            'East'
        );

        [$gradeEight, $north] = $this->gradeAndStream(
            $school,
            'Grade 8',
            'North'
        );

        $learner = $this->learner(
            $school,
            $gradeSeven,
            $east
        );

        $this->withToken($this->tokenFor($user))
            ->postJson(
                "/api/learners/{$learner->id}/placements",
                [
                    'grade_id' => $gradeEight->id,
                    'stream_id' => $north->id,
                    'reason' => 'Corrected academic placement.',
                ]
            )
            ->assertSuccessful()
            ->assertJsonPath(
                'data.placement.placement_type',
                'grade_change'
            );

        $this->assertDatabaseHas('learners', [
            'id' => $learner->id,
            'grade_id' => $gradeEight->id,
            'stream_id' => $north->id,
        ]);

        $this->assertDatabaseHas(
            'learner_placement_history',
            [
                'learner_id' => $learner->id,
                'from_grade_id' => $gradeSeven->id,
                'from_stream_id' => $east->id,
                'to_grade_id' => $gradeEight->id,
                'to_stream_id' => $north->id,
                'placement_type' => 'grade_change',
            ]
        );
    }

    public function test_foreign_learner_cannot_be_repositioned(): void
    {
        [$school, $user] = $this->schoolAdminWithPermission();

        $foreignSchool = $this->school();

        [$foreignGrade, $foreignStream] =
            $this->gradeAndStream($foreignSchool);

        $foreignLearner = $this->learner(
            $foreignSchool,
            $foreignGrade,
            $foreignStream
        );

        [$localGrade, $localStream] =
            $this->gradeAndStream(
                $school,
                'Grade 8',
                'West'
            );

        $this->withToken($this->tokenFor($user))
            ->postJson(
                "/api/learners/{$foreignLearner->id}/placements",
                [
                    'grade_id' => $localGrade->id,
                    'stream_id' => $localStream->id,
                ]
            )
            ->assertNotFound();

        $this->assertDatabaseMissing(
            'learner_placement_history',
            [
                'learner_id' => $foreignLearner->id,
                'school_id' => $school->id,
            ]
        );
    }

    public function test_foreign_grade_is_rejected(): void
    {
        [$school, $user] = $this->schoolAdminWithPermission();

        [$grade, $stream] =
            $this->gradeAndStream($school);

        $learner = $this->learner(
            $school,
            $grade,
            $stream
        );

        $foreignSchool = $this->school();

        [$foreignGrade, $foreignStream] =
            $this->gradeAndStream($foreignSchool);

        $this->withToken($this->tokenFor($user))
            ->postJson(
                "/api/learners/{$learner->id}/placements",
                [
                    'grade_id' => $foreignGrade->id,
                    'stream_id' => $foreignStream->id,
                ]
            )
            ->assertStatus(422)
            ->assertJsonValidationErrors('grade_id');

        $this->assertDatabaseHas('learners', [
            'id' => $learner->id,
            'grade_id' => $grade->id,
            'stream_id' => $stream->id,
        ]);
    }

    public function test_foreign_stream_is_rejected(): void
    {
        [$school, $user] = $this->schoolAdminWithPermission();

        [$grade, $stream] =
            $this->gradeAndStream($school);

        $learner = $this->learner(
            $school,
            $grade,
            $stream
        );

        $foreignSchool = $this->school();

        [, $foreignStream] =
            $this->gradeAndStream($foreignSchool);

        $this->withToken($this->tokenFor($user))
            ->postJson(
                "/api/learners/{$learner->id}/placements",
                [
                    'grade_id' => $grade->id,
                    'stream_id' => $foreignStream->id,
                ]
            )
            ->assertStatus(422)
            ->assertJsonValidationErrors('stream_id');
    }

    public function test_stream_must_belong_to_selected_grade(): void
    {
        [$school, $user] = $this->schoolAdminWithPermission();

        [$gradeSeven, $east] =
            $this->gradeAndStream(
                $school,
                'Grade 7',
                'East'
            );

        [$gradeEight, $west] =
            $this->gradeAndStream(
                $school,
                'Grade 8',
                'West'
            );

        $learner = $this->learner(
            $school,
            $gradeSeven,
            $east
        );

        $this->withToken($this->tokenFor($user))
            ->postJson(
                "/api/learners/{$learner->id}/placements",
                [
                    'grade_id' => $gradeSeven->id,
                    'stream_id' => $west->id,
                ]
            )
            ->assertStatus(422)
            ->assertJsonValidationErrors('stream_id');

        $this->assertDatabaseHas('learners', [
            'id' => $learner->id,
            'grade_id' => $gradeSeven->id,
            'stream_id' => $east->id,
        ]);

        $this->assertDatabaseMissing(
            'learner_placement_history',
            [
                'learner_id' => $learner->id,
                'to_grade_id' => $gradeEight->id,
            ]
        );
    }

    public function test_inactive_grade_is_rejected(): void
    {
        [$school, $user] = $this->schoolAdminWithPermission();

        [$currentGrade, $currentStream] =
            $this->gradeAndStream(
                $school,
                'Grade 7',
                'East'
            );

        [$targetGrade, $targetStream] =
            $this->gradeAndStream(
                $school,
                'Grade 8',
                'North'
            );

        $targetGrade->update([
            'active' => false,
        ]);

        $learner = $this->learner(
            $school,
            $currentGrade,
            $currentStream
        );

        $this->withToken($this->tokenFor($user))
            ->postJson(
                "/api/learners/{$learner->id}/placements",
                [
                    'grade_id' => $targetGrade->id,
                    'stream_id' => $targetStream->id,
                ]
            )
            ->assertStatus(422)
            ->assertJsonValidationErrors('grade_id');
    }

    public function test_inactive_stream_is_rejected(): void
    {
        [$school, $user] = $this->schoolAdminWithPermission();

        [$grade, $east] =
            $this->gradeAndStream(
                $school,
                'Grade 7',
                'East'
            );

        $west = $this->stream(
            $school,
            $grade,
            'West'
        );

        $west->update([
            'active' => false,
        ]);

        $learner = $this->learner(
            $school,
            $grade,
            $east
        );

        $this->withToken($this->tokenFor($user))
            ->postJson(
                "/api/learners/{$learner->id}/placements",
                [
                    'grade_id' => $grade->id,
                    'stream_id' => $west->id,
                ]
            )
            ->assertStatus(422)
            ->assertJsonValidationErrors('stream_id');
    }

    public function test_inactive_learner_cannot_be_repositioned(): void
    {
        [$school, $user] = $this->schoolAdminWithPermission();

        [$grade, $east] =
            $this->gradeAndStream(
                $school,
                'Grade 7',
                'East'
            );

        $west = $this->stream(
            $school,
            $grade,
            'West'
        );

        $learner = $this->learner(
            $school,
            $grade,
            $east
        );

        $learner->update([
            'active' => false,
        ]);

        $this->withToken($this->tokenFor($user))
            ->postJson(
                "/api/learners/{$learner->id}/placements",
                [
                    'grade_id' => $grade->id,
                    'stream_id' => $west->id,
                ]
            )
            ->assertStatus(422)
            ->assertJsonValidationErrors('learner');

        $this->assertDatabaseMissing(
            'learner_placement_history',
            [
                'learner_id' => $learner->id,
            ]
        );
    }

    public function test_deleted_learner_cannot_be_repositioned(): void
    {
        [$school, $user] = $this->schoolAdminWithPermission();

        [$grade, $east] =
            $this->gradeAndStream(
                $school,
                'Grade 7',
                'East'
            );

        $west = $this->stream(
            $school,
            $grade,
            'West'
        );

        $learner = $this->learner(
            $school,
            $grade,
            $east
        );

        DB::table('learners')
            ->where('id', $learner->id)
            ->update([
                'is_deleted' => true,
                'deleted_at' => now(),
                'deleted_by' => $user->id,
            ]);

        $this->withToken($this->tokenFor($user))
            ->postJson(
                "/api/learners/{$learner->id}/placements",
                [
                    'grade_id' => $grade->id,
                    'stream_id' => $west->id,
                ]
            )
            ->assertNotFound();
    }

    public function test_same_placement_is_rejected_without_history_row(): void
    {
        [$school, $user] = $this->schoolAdminWithPermission();

        [$grade, $stream] =
            $this->gradeAndStream($school);

        $learner = $this->learner(
            $school,
            $grade,
            $stream
        );

        $this->withToken($this->tokenFor($user))
            ->postJson(
                "/api/learners/{$learner->id}/placements",
                [
                    'grade_id' => $grade->id,
                    'stream_id' => $stream->id,
                ]
            )
            ->assertStatus(422)
            ->assertJsonValidationErrors('placement');

        $this->assertDatabaseMissing(
            'learner_placement_history',
            [
                'learner_id' => $learner->id,
            ]
        );
    }

    public function test_client_cannot_override_server_controlled_history_fields(): void
    {
        [$school, $user] = $this->schoolAdminWithPermission();

        [$grade, $east] =
            $this->gradeAndStream(
                $school,
                'Grade 7',
                'East'
            );

        $west = $this->stream(
            $school,
            $grade,
            'West'
        );

        $learner = $this->learner(
            $school,
            $grade,
            $east
        );

        $foreignSchool = $this->school();
        $foreignUser = $this->user($foreignSchool);

        $fakeTime = '2001-01-01 00:00:00';

        $this->withToken($this->tokenFor($user))
            ->postJson(
                "/api/learners/{$learner->id}/placements",
                [
                    'grade_id' => $grade->id,
                    'stream_id' => $west->id,

                    'school_id' => $foreignSchool->id,
                    'learner_id' => (string) Str::uuid(),
                    'from_grade_id' => (string) Str::uuid(),
                    'from_stream_id' => (string) Str::uuid(),
                    'to_grade_id' => (string) Str::uuid(),
                    'to_stream_id' => (string) Str::uuid(),
                    'placement_type' => 'hacked',
                    'placed_by' => $foreignUser->id,
                    'placed_at' => $fakeTime,
                    'created_at' => $fakeTime,
                ]
            )
            ->assertSuccessful();

        $history = DB::table(
            'learner_placement_history'
        )
            ->where('learner_id', $learner->id)
            ->first();

        $this->assertNotNull($history);

        $this->assertSame(
            $school->id,
            $history->school_id
        );

        $this->assertSame(
            $learner->id,
            $history->learner_id
        );

        $this->assertSame(
            $grade->id,
            $history->from_grade_id
        );

        $this->assertSame(
            $east->id,
            $history->from_stream_id
        );

        $this->assertSame(
            $grade->id,
            $history->to_grade_id
        );

        $this->assertSame(
            $west->id,
            $history->to_stream_id
        );

        $this->assertSame(
            'stream_change',
            $history->placement_type
        );

        $this->assertSame(
            $user->id,
            $history->placed_by
        );

        $this->assertNotSame(
            $fakeTime,
            (string) $history->placed_at
        );
    }

    public function test_generic_learner_update_cannot_change_grade_or_stream(): void
    {
        [$school, $user] = $this->schoolAdminWithPermission();

        [$gradeSeven, $east] =
            $this->gradeAndStream(
                $school,
                'Grade 7',
                'East'
            );

        [$gradeEight, $west] =
            $this->gradeAndStream(
                $school,
                'Grade 8',
                'West'
            );

        $learner = $this->learner(
            $school,
            $gradeSeven,
            $east
        );

        $this->withToken($this->tokenFor($user))
            ->putJson(
                "/api/learners/{$learner->id}",
                [
                    'grade_id' => $gradeEight->id,
                    'stream_id' => $west->id,
                ]
            )
            ->assertStatus(422)
            ->assertJsonValidationErrors('placement');

        $this->assertDatabaseHas('learners', [
            'id' => $learner->id,
            'grade_id' => $gradeSeven->id,
            'stream_id' => $east->id,
        ]);

        $this->assertDatabaseMissing(
            'learner_placement_history',
            [
                'learner_id' => $learner->id,
            ]
        );
    }

    public function test_generic_profile_update_still_works_without_placement_fields(): void
    {
        [$school, $user] = $this->schoolAdminWithPermission();

        [$grade, $stream] =
            $this->gradeAndStream($school);

        $learner = $this->learner(
            $school,
            $grade,
            $stream
        );

        $this->withToken($this->tokenFor($user))
            ->putJson(
                "/api/learners/{$learner->id}",
                [
                    'first_name' => 'Updated',
                ]
            )
            ->assertSuccessful();

        $this->assertDatabaseHas('learners', [
            'id' => $learner->id,
            'first_name' => 'Updated',
            'grade_id' => $grade->id,
            'stream_id' => $stream->id,
        ]);
    }

    public function test_placement_history_is_tenant_scoped(): void
    {
        [$school, $user] = $this->schoolAdminWithPermission();

        /*
         * Build all tenant fixtures before making an authenticated
         * request. Tenant-aware models must not inherit request auth
         * context while foreign-school fixtures are being prepared.
         */
        [$grade, $east] = $this->gradeAndStream(
            $school,
            'Grade 7',
            'East'
        );

        $west = $this->stream(
            $school,
            $grade,
            'West'
        );

        $learner = $this->learner(
            $school,
            $grade,
            $east
        );

        $foreignSchool = $this->school();

        [$foreignGrade, $foreignStream] =
            $this->gradeAndStream(
                $foreignSchool,
                'Grade 7',
                'East'
            );

        $foreignLearner = $this->learner(
            $foreignSchool,
            $foreignGrade,
            $foreignStream,
            'FOREIGN-PLACEMENT'
        );

        $foreignUser = $this->user(
            $foreignSchool
        );

        /*
         * Create the foreign history record before authentication
         * establishes the local school's tenant context.
         */
        DB::table('learner_placement_history')
            ->insert([
                'id' => (string) Str::uuid(),
                'school_id' => $foreignSchool->id,
                'learner_id' => $foreignLearner->id,
                'from_grade_id' => $foreignGrade->id,
                'from_stream_id' => $foreignStream->id,
                'to_grade_id' => $foreignGrade->id,
                'to_stream_id' => $foreignStream->id,
                'placement_type' => 'stream_change',
                'reason' => 'Foreign history',
                'placed_by' => $foreignUser->id,
                'placed_at' => now(),
                'created_at' => now(),
            ]);

        /*
         * Now perform the local placement through the secured API.
         */
        $this->withToken(
            $this->tokenFor($user)
        )
            ->postJson(
                "/api/learners/{$learner->id}/placements",
                [
                    'grade_id' => $grade->id,
                    'stream_id' => $west->id,
                ]
            )
            ->assertSuccessful();

        $response = $this->withToken(
            $this->tokenFor($user)
        )
            ->getJson(
                "/api/learners/{$learner->id}/placements"
            )
            ->assertSuccessful();

        $content = $response->getContent();

        $this->assertStringContainsString(
            $learner->id,
            $content
        );

        $this->assertStringNotContainsString(
            $foreignLearner->id,
            $content
        );

        $this->assertStringNotContainsString(
            'Foreign history',
            $content
        );
    }

    public function test_foreign_learner_placement_history_cannot_be_viewed(): void
    {
        [$school, $user] = $this->schoolAdminWithPermission();

        $foreignSchool = $this->school();

        [$foreignGrade, $foreignStream] =
            $this->gradeAndStream($foreignSchool);

        $foreignLearner = $this->learner(
            $foreignSchool,
            $foreignGrade,
            $foreignStream
        );

        $this->withToken($this->tokenFor($user))
            ->getJson(
                "/api/learners/{$foreignLearner->id}/placements"
            )
            ->assertNotFound();
    }

    public function test_placement_operation_is_audited(): void
    {
        [$school, $user] = $this->schoolAdminWithPermission();

        [$grade, $east] =
            $this->gradeAndStream(
                $school,
                'Grade 7',
                'East'
            );

        $west = $this->stream(
            $school,
            $grade,
            'West'
        );

        $learner = $this->learner(
            $school,
            $grade,
            $east
        );

        $this->withToken($this->tokenFor($user))
            ->postJson(
                "/api/learners/{$learner->id}/placements",
                [
                    'grade_id' => $grade->id,
                    'stream_id' => $west->id,
                ]
            )
            ->assertSuccessful();

        $audit = DB::table('audit_logs')
            ->where('school_id', $school->id)
            ->where('user_id', $user->id)
            ->where('module', 'Learner Placement')
            ->where('action', 'Place')
            ->where('table_name', 'learners')
            ->where('record_id', $learner->id)
            ->first();

        $this->assertNotNull($audit);
    }

    public function test_placement_routes_require_manage_learners_permission(): void
    {
        $routes = collect(
            app('router')->getRoutes()->getRoutes()
        );

        foreach ([
            ['GET', 'api/learners/{learner}/placements'],
            ['POST', 'api/learners/{learner}/placements'],
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

    public function test_placement_routes_do_not_require_operational_setup_gate(): void
    {
        $routes = collect(
            app('router')->getRoutes()->getRoutes()
        );

        foreach ([
            ['GET', 'api/learners/{learner}/placements'],
            ['POST', 'api/learners/{learner}/placements'],
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

    public function test_unauthenticated_user_cannot_change_placement(): void
    {
        $school = $this->school();

        [$grade, $east] =
            $this->gradeAndStream(
                $school,
                'Grade 7',
                'East'
            );

        $west = $this->stream(
            $school,
            $grade,
            'West'
        );

        $learner = $this->learner(
            $school,
            $grade,
            $east
        );

        $this->postJson(
            "/api/learners/{$learner->id}/placements",
            [
                'grade_id' => $grade->id,
                'stream_id' => $west->id,
            ]
        )
            ->assertUnauthorized();
    }

    public function test_user_without_manage_learners_permission_cannot_change_placement(): void
    {
        $school = $this->school();
        $user = $this->user($school);

        [$grade, $east] =
            $this->gradeAndStream(
                $school,
                'Grade 7',
                'East'
            );

        $west = $this->stream(
            $school,
            $grade,
            'West'
        );

        $learner = $this->learner(
            $school,
            $grade,
            $east
        );

        $this->withToken($this->tokenFor($user))
            ->postJson(
                "/api/learners/{$learner->id}/placements",
                [
                    'grade_id' => $grade->id,
                    'stream_id' => $west->id,
                ]
            )
            ->assertStatus(403);

        $this->assertDatabaseHas('learners', [
            'id' => $learner->id,
            'stream_id' => $east->id,
        ]);
    }

    public function test_reason_is_limited_to_500_characters(): void
    {
        [$school, $user] = $this->schoolAdminWithPermission();

        [$grade, $east] =
            $this->gradeAndStream(
                $school,
                'Grade 7',
                'East'
            );

        $west = $this->stream(
            $school,
            $grade,
            'West'
        );

        $learner = $this->learner(
            $school,
            $grade,
            $east
        );

        $this->withToken($this->tokenFor($user))
            ->postJson(
                "/api/learners/{$learner->id}/placements",
                [
                    'grade_id' => $grade->id,
                    'stream_id' => $west->id,
                    'reason' => str_repeat('x', 501),
                ]
            )
            ->assertStatus(422)
            ->assertJsonValidationErrors('reason');

        $this->assertDatabaseMissing(
            'learner_placement_history',
            [
                'learner_id' => $learner->id,
            ]
        );
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

        $stream = $this->stream(
            $school,
            $grade,
            $streamName
        );

        return [$grade, $stream];
    }

    private function stream(
        School $school,
        Grade $grade,
        string $name
    ): Stream {
        return Stream::query()
            ->withoutGlobalScopes()
            ->create([
                'id' => (string) Str::uuid(),
                'school_id' => $school->id,
                'grade_id' => $grade->id,
                'stream_name' => $name,
                'active' => true,
                'created_at' => now(),
            ]);
    }

    private function learner(
        School $school,
        Grade $grade,
        Stream $stream,
        string $admissionNo = 'ADM-PLACEMENT'
    ): Learner {
        return Learner::query()
            ->withoutGlobalScopes()
            ->create([
                'id' => (string) Str::uuid(),
                'school_id' => $school->id,
                'admission_no' => $admissionNo.'-'.
                    Str::upper(Str::random(6)),
                'first_name' => 'Existing',
                'last_name' => 'Learner',
                'grade_id' => $grade->id,
                'stream_id' => $stream->id,
                'admission_date' => now()->toDateString(),
                'active' => true,
                'is_deleted' => false,
                'portal_enabled' => false,
            ]);
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
