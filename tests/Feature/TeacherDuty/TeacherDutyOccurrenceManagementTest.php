<?php

declare(strict_types=1);

namespace Tests\Feature\TeacherDuty;

use App\Models\Role;
use App\Models\School;
use App\Models\Teacher;
use App\Models\TeacherDutyAssignment;
use App\Models\TeacherDutyPeriod;
use App\Models\User;
use App\Services\TeacherDuty\TeacherDutyOccurrenceCategoryProvisioningService;
use App\Services\TeacherDuty\TeacherDutyOccurrenceService;
use App\Services\TeacherDuty\TeacherDutyRosterService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Tests\Support\Database\TeacherBuilder;
use Tests\TestCase;

final class TeacherDutyOccurrenceManagementTest extends TestCase
{
    use DatabaseTransactions;

    private School $school;

    private User $reporter;

    private User $administrator;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'jwt.secret' => str_repeat('a', 64),
        ]);

        $this->school = $this->createSchool();
        $this->completeOperationalSetup($this->school);

        $this->reporter = $this->createUser($this->school);
        $this->grantPermission(
            $this->reporter,
            'submit_teacher_duty_reports'
        );

        $this->administrator = $this->createUser($this->school);
        $this->grantPermission(
            $this->administrator,
            'manage_teacher_duty_roster'
        );
    }

    public function test_routes_have_frozen_security_contract(): void
    {
        $routes = collect(Route::getRoutes()->getRoutes());

        $contracts = [
            [
                'GET',
                'api/teacher-duty/occurrence-categories',
                'permission:submit_teacher_duty_reports',
                false,
            ],
            [
                'POST',
                'api/teacher-duty/occurrence-categories',
                'permission:manage_teacher_duty_roster',
                true,
            ],
            [
                'PATCH',
                'api/teacher-duty/occurrence-categories/{category}/deactivate',
                'permission:manage_teacher_duty_roster',
                true,
            ],
            [
                'GET',
                'api/teacher-duty/periods/{period}/occurrences',
                'permission:submit_teacher_duty_reports',
                false,
            ],
            [
                'POST',
                'api/teacher-duty/periods/{period}/occurrences',
                'permission:submit_teacher_duty_reports',
                true,
            ],
            [
                'GET',
                'api/teacher-duty/occurrences/{occurrence}',
                'permission:submit_teacher_duty_reports',
                false,
            ],
        ];

        foreach (
            $contracts as [
                $method,
                $uri,
                $permission,
                $operational,
            ]
        ) {
            $route = $routes->first(
                fn ($candidate): bool => in_array(
                    $method,
                    $candidate->methods(),
                    true
                ) && $candidate->uri() === $uri
            );

            $this->assertNotNull(
                $route,
                "{$method} {$uri} must be registered."
            );

            $middleware = $route->gatherMiddleware();

            $this->assertContains(
                $permission,
                $middleware,
                "{$method} {$uri} must require {$permission}."
            );

            if (
                $permission ===
                'permission:submit_teacher_duty_reports'
            ) {
                $this->assertNotContains(
                    'permission:manage_teacher_duty_roster',
                    $middleware,
                    "{$method} {$uri} must not require roster administration."
                );
            } else {
                $this->assertNotContains(
                    'permission:submit_teacher_duty_reports',
                    $middleware,
                    "{$method} {$uri} must not require reporter permission."
                );
            }

            if ($operational) {
                $this->assertContains(
                    'school.operational',
                    $middleware,
                    "{$method} {$uri} must require school.operational."
                );
            } else {
                $this->assertNotContains(
                    'school.operational',
                    $middleware,
                    "{$method} {$uri} must remain readable outside the operational gate."
                );
            }
        }
    }

    public function test_reporter_can_list_categories_without_period_responsibility(): void
    {
        $this->actingAsJwt($this->reporter);

        $response = $this->getJson(
            '/api/teacher-duty/occurrence-categories'
        );

        $response
            ->assertOk()
            ->assertJsonStructure([
                'data',
            ]);
    }

    public function test_reporter_with_period_responsibility_can_list_occurrences(): void
    {
        $teacher = $this->teacherForUser($this->reporter);
        $period = $this->createDutyPeriod($this->reporter);

        $this->createDutyAssignment(
            $period,
            $teacher,
            $this->reporter
        );

        $this->actingAsJwt($this->reporter);

        $response = $this->getJson(
            "/api/teacher-duty/periods/{$period->id}/occurrences"
        );

        $response
            ->assertOk()
            ->assertExactJson([
                'data' => [],
            ]);
    }

    public function test_reporter_without_period_responsibility_cannot_list_occurrences(): void
    {
        $this->ensureTeacherForUser($this->reporter);
        $period = $this->createDutyPeriod($this->reporter);

        $this->actingAsJwt($this->reporter);

        $response = $this->getJson(
            "/api/teacher-duty/periods/{$period->id}/occurrences"
        );

        $response
            ->assertStatus(422)
            ->assertJsonValidationErrors([
                'actor',
            ]);
    }

    public function test_ended_preserved_assignment_still_authorizes_occurrence_read(): void
    {
        $teacher = $this->teacherForUser($this->reporter);
        $period = $this->createDutyPeriod($this->reporter);

        $this->createDutyAssignment(
            $period,
            $teacher,
            $this->reporter,
            false
        );

        $this->assertSame(
            0,
            DB::table('teacher_duty_assignments')
                ->where('school_id', $this->school->id)
                ->where('duty_period_id', $period->id)
                ->where('teacher_id', $teacher->id)
                ->where('active', true)
                ->count()
        );

        $this->assertSame(
            1,
            DB::table('teacher_duty_assignments')
                ->where('school_id', $this->school->id)
                ->where('duty_period_id', $period->id)
                ->where('teacher_id', $teacher->id)
                ->count()
        );

        $this->actingAsJwt($this->reporter);

        $response = $this->getJson(
            "/api/teacher-duty/periods/{$period->id}/occurrences"
        );

        $response
            ->assertOk()
            ->assertExactJson([
                'data' => [],
            ]);
    }

    public function test_reporter_with_period_responsibility_can_record_occurrence(): void
    {
        $teacher = $this->teacherForUser($this->reporter);
        $period = $this->createDutyPeriod($this->reporter);

        $this->createDutyAssignment(
            $period,
            $teacher,
            $this->reporter
        );

        $categoryId = $this->canonicalCategoryId('discipline');

        $this->actingAsJwt($this->reporter);

        $response = $this->postJson(
            "/api/teacher-duty/periods/{$period->id}/occurrences",
            [
                'occurrence_category_id' => $categoryId,
                'occurrence_date' => '2026-09-09',
                'occurrence_time' => '10:15',
                'description' => 'Learner discipline incident recorded.',
            ]
        );

        $response
            ->assertCreated()
            ->assertJsonPath(
                'data.duty_period_id',
                (string) $period->id
            )
            ->assertJsonPath(
                'data.occurrence_category_id',
                $categoryId
            )
            ->assertJsonPath(
                'data.recorded_by',
                (string) $this->reporter->id
            );

        $this->assertDatabaseHas(
            'teacher_duty_occurrences',
            [
                'school_id' => $this->school->id,
                'duty_period_id' => $period->id,
                'occurrence_category_id' => $categoryId,
                'recorded_by' => $this->reporter->id,
                'description' => 'Learner discipline incident recorded.',
            ]
        );
    }

    public function test_reporter_without_period_responsibility_cannot_record_occurrence(): void
    {
        $this->ensureTeacherForUser($this->reporter);
        $period = $this->createDutyPeriod($this->reporter);
        $categoryId = $this->canonicalCategoryId('discipline');

        $this->actingAsJwt($this->reporter);

        $response = $this->postJson(
            "/api/teacher-duty/periods/{$period->id}/occurrences",
            [
                'occurrence_category_id' => $categoryId,
                'occurrence_date' => '2026-09-09',
                'occurrence_time' => null,
                'description' => 'Unauthorized responsibility attempt.',
            ]
        );

        $response
            ->assertStatus(422)
            ->assertJsonValidationErrors([
                'actor',
            ]);

        $this->assertDatabaseMissing(
            'teacher_duty_occurrences',
            [
                'school_id' => $this->school->id,
                'duty_period_id' => $period->id,
                'description' => 'Unauthorized responsibility attempt.',
            ]
        );
    }

    public function test_occurrence_show_uses_actual_period_responsibility(): void
    {
        $teacher = $this->teacherForUser($this->reporter);

        $responsiblePeriod = $this->createDutyPeriod(
            $this->reporter
        );

        $this->createDutyAssignment(
            $responsiblePeriod,
            $teacher,
            $this->reporter
        );

        $categoryId = $this->canonicalCategoryId('discipline');

        $occurrence = app(TeacherDutyOccurrenceService::class)
            ->recordOccurrence(
                (string) $this->school->id,
                (string) $responsiblePeriod->id,
                $categoryId,
                '2026-09-09',
                null,
                'Occurrence readable by responsible reporter.',
                (string) $this->reporter->id
            );

        $this->actingAsJwt($this->reporter);

        $response = $this->getJson(
            "/api/teacher-duty/occurrences/{$occurrence->id}"
        );

        $response
            ->assertOk()
            ->assertJsonPath(
                'data.id',
                (string) $occurrence->id
            )
            ->assertJsonPath(
                'data.duty_period_id',
                (string) $responsiblePeriod->id
            );
    }

    public function test_responsible_teacher_without_submit_permission_is_forbidden(): void
    {
        $user = $this->createUser($this->school);
        $teacher = $this->ensureTeacherForUser($user);
        $period = $this->createDutyPeriod($this->administrator);

        $this->createDutyAssignment(
            $period,
            $teacher,
            $this->administrator
        );

        $this->actingAsJwt($user);

        $response = $this->getJson(
            "/api/teacher-duty/periods/{$period->id}/occurrences"
        );

        $response->assertForbidden();
    }

    public function test_roster_administrator_can_create_custom_occurrence_category(): void
    {
        $this->actingAsJwt($this->administrator);

        $response = $this->postJson(
            '/api/teacher-duty/occurrence-categories',
            [
                'code' => 'pastoral_support',
                'name' => 'Pastoral Support',
                'description' => 'Pastoral support occurrences.',
                'display_order' => 90,
            ]
        );

        $response
            ->assertCreated()
            ->assertJsonPath(
                'data.code',
                'pastoral_support'
            )
            ->assertJsonPath(
                'data.name',
                'Pastoral Support'
            )
            ->assertJsonPath(
                'data.is_canonical',
                false
            )
            ->assertJsonPath(
                'data.active',
                true
            )
            ->assertJsonPath(
                'data.created_by',
                (string) $this->administrator->id
            );

        $this->assertDatabaseHas(
            'teacher_duty_occurrence_categories',
            [
                'school_id' => $this->school->id,
                'code' => 'pastoral_support',
                'name' => 'Pastoral Support',
                'is_canonical' => false,
                'active' => true,
                'created_by' => $this->administrator->id,
            ]
        );
    }

    public function test_reporter_only_cannot_create_custom_occurrence_category(): void
    {
        $this->actingAsJwt($this->reporter);

        $response = $this->postJson(
            '/api/teacher-duty/occurrence-categories',
            [
                'code' => 'pastoral_support',
                'name' => 'Pastoral Support',
                'description' => null,
                'display_order' => 90,
            ]
        );

        $response->assertForbidden();

        $this->assertDatabaseMissing(
            'teacher_duty_occurrence_categories',
            [
                'school_id' => $this->school->id,
                'code' => 'pastoral_support',
            ]
        );
    }

    public function test_roster_administrator_can_deactivate_custom_occurrence_category(): void
    {
        $category = app(TeacherDutyOccurrenceService::class)
            ->createCustomCategory(
                (string) $this->school->id,
                'pastoral_support',
                'Pastoral Support',
                'Pastoral support occurrences.',
                90,
                (string) $this->administrator->id
            );

        $this->actingAsJwt($this->administrator);

        $response = $this->patchJson(
            "/api/teacher-duty/occurrence-categories/{$category->id}/deactivate",
            []
        );

        $response
            ->assertOk()
            ->assertJsonPath(
                'data.id',
                (string) $category->id
            )
            ->assertJsonPath(
                'data.active',
                false
            )
            ->assertJsonPath(
                'data.deactivated_by',
                (string) $this->administrator->id
            );

        $this->assertDatabaseHas(
            'teacher_duty_occurrence_categories',
            [
                'id' => $category->id,
                'school_id' => $this->school->id,
                'active' => false,
                'deactivated_by' => $this->administrator->id,
            ]
        );
    }

    public function test_reporter_only_cannot_deactivate_occurrence_category(): void
    {
        $category = app(TeacherDutyOccurrenceService::class)
            ->createCustomCategory(
                (string) $this->school->id,
                'pastoral_support',
                'Pastoral Support',
                null,
                90,
                (string) $this->administrator->id
            );

        $this->actingAsJwt($this->reporter);

        $response = $this->patchJson(
            "/api/teacher-duty/occurrence-categories/{$category->id}/deactivate",
            []
        );

        $response->assertForbidden();

        $this->assertDatabaseHas(
            'teacher_duty_occurrence_categories',
            [
                'id' => $category->id,
                'school_id' => $this->school->id,
                'active' => true,
                'deactivated_by' => null,
            ]
        );
    }

    public function test_occurrence_store_validates_frozen_payload_contract(): void
    {
        $teacher = $this->teacherForUser($this->reporter);
        $period = $this->createDutyPeriod($this->reporter);

        $this->createDutyAssignment(
            $period,
            $teacher,
            $this->reporter
        );

        $this->actingAsJwt($this->reporter);

        $response = $this->postJson(
            "/api/teacher-duty/periods/{$period->id}/occurrences",
            [
                'occurrence_category_id' => 'not-a-uuid',
                'occurrence_date' => '09-09-2026',
                'occurrence_time' => '25:61',
                'description' => str_repeat('x', 5001),
            ]
        );

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'occurrence_category_id',
                'occurrence_date',
                'occurrence_time',
                'description',
            ]);
    }

    public function test_occurrence_store_accepts_nullable_time(): void
    {
        $teacher = $this->teacherForUser($this->reporter);
        $period = $this->createDutyPeriod($this->reporter);

        $this->createDutyAssignment(
            $period,
            $teacher,
            $this->reporter
        );

        $categoryId = $this->canonicalCategoryId('discipline');

        $this->actingAsJwt($this->reporter);

        $response = $this->postJson(
            "/api/teacher-duty/periods/{$period->id}/occurrences",
            [
                'occurrence_category_id' => $categoryId,
                'occurrence_date' => '2026-09-09',
                'occurrence_time' => null,
                'description' => 'Occurrence without a specific time.',
            ]
        );

        $response
            ->assertCreated()
            ->assertJsonPath(
                'data.occurrence_time',
                null
            );
    }

    public function test_client_cannot_control_occurrence_server_fields(): void
    {
        $teacher = $this->teacherForUser($this->reporter);
        $period = $this->createDutyPeriod($this->reporter);
        $otherPeriod = $this->createDutyPeriod(
            $this->administrator
        );

        $this->createDutyAssignment(
            $period,
            $teacher,
            $this->reporter
        );

        $categoryId = $this->canonicalCategoryId('discipline');

        $this->actingAsJwt($this->reporter);

        $response = $this->postJson(
            "/api/teacher-duty/periods/{$period->id}/occurrences",
            [
                'occurrence_category_id' => $categoryId,
                'occurrence_date' => '2026-09-09',
                'description' => 'Client ownership attempt.',
                'id' => (string) Str::uuid(),
                'duty_period_id' => (string) $otherPeriod->id,
                'recorded_by' => (string) $this->administrator->id,
                'created_by' => (string) $this->administrator->id,
                'deactivated_by' => (string) $this->administrator->id,
                'deactivated_at' => now()->toISOString(),
                'is_canonical' => true,
                'active' => false,
                'created_at' => now()->toISOString(),
                'updated_at' => now()->toISOString(),
            ]
        );

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'id',
                'duty_period_id',
                'recorded_by',
                'created_by',
                'deactivated_by',
                'deactivated_at',
                'is_canonical',
                'active',
                'created_at',
                'updated_at',
            ]);

        $this->assertDatabaseMissing(
            'teacher_duty_occurrences',
            [
                'school_id' => $this->school->id,
                'description' => 'Client ownership attempt.',
            ]
        );
    }

    public function test_client_cannot_control_occurrence_school_id(): void
    {
        $teacher = $this->teacherForUser($this->reporter);
        $period = $this->createDutyPeriod($this->reporter);
        $foreignSchool = $this->createSchool();

        $this->createDutyAssignment(
            $period,
            $teacher,
            $this->reporter
        );

        $categoryId = $this->canonicalCategoryId('discipline');

        $this->actingAsJwt($this->reporter);

        $response = $this->postJson(
            "/api/teacher-duty/periods/{$period->id}/occurrences",
            [
                'school_id' => (string) $foreignSchool->id,
                'occurrence_category_id' => $categoryId,
                'occurrence_date' => '2026-09-09',
                'description' => 'Foreign school ownership attempt.',
            ]
        );

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'school_id',
            ]);

        $this->assertDatabaseMissing(
            'teacher_duty_occurrences',
            [
                'school_id' => $foreignSchool->id,
                'description' => 'Foreign school ownership attempt.',
            ]
        );
    }

    public function test_malformed_occurrence_route_identifiers_fail_as_generic_422_without_database_leak(): void
    {
        $this->actingAsJwt($this->reporter);

        foreach ([
            ['GET', '/api/teacher-duty/periods/not-a-uuid/occurrences'],
            ['GET', '/api/teacher-duty/occurrences/not-a-uuid'],
        ] as [$method, $uri]) {
            $response = $this->getJson($uri);

            $response
                ->assertUnprocessable()
                ->assertJsonValidationErrors([
                    'resource',
                ]);

            $body = strtolower($response->getContent());

            foreach ([
                'sqlstate',
                'postgres',
                'invalid input syntax',
                'queryexception',
                'pdoexception',
                'stack trace',
                'vendor/',
            ] as $leak) {
                $this->assertStringNotContainsString(
                    $leak,
                    $body
                );
            }
        }
    }

    public function test_category_store_validates_frozen_payload_contract(): void
    {
        $this->actingAsJwt($this->administrator);

        $response = $this->postJson(
            '/api/teacher-duty/occurrence-categories',
            [
                'code' => str_repeat('c', 101),
                'name' => str_repeat('n', 151),
                'description' => ['not', 'a', 'string'],
                'display_order' => -1,
            ]
        );

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'code',
                'name',
                'description',
                'display_order',
            ]);
    }

    public function test_client_cannot_control_category_server_fields(): void
    {
        $this->actingAsJwt($this->administrator);

        $response = $this->postJson(
            '/api/teacher-duty/occurrence-categories',
            [
                'code' => 'client_control_probe',
                'name' => 'Client Control Probe',
                'description' => 'Should not be created.',
                'display_order' => 95,
                'id' => (string) Str::uuid(),
                'is_canonical' => true,
                'active' => false,
                'created_by' => (string) $this->reporter->id,
                'deactivated_by' => (string) $this->reporter->id,
                'deactivated_at' => now()->toISOString(),
                'created_at' => now()->toISOString(),
                'updated_at' => now()->toISOString(),
            ]
        );

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'id',
                'is_canonical',
                'active',
                'created_by',
                'deactivated_by',
                'created_at',
                'updated_at',
            ]);

        $this->assertDatabaseMissing(
            'teacher_duty_occurrence_categories',
            [
                'school_id' => $this->school->id,
                'code' => 'client_control_probe',
            ]
        );
    }

    public function test_client_cannot_control_category_school_id(): void
    {
        $foreignSchool = $this->createSchool();

        $this->actingAsJwt($this->administrator);

        $response = $this->postJson(
            '/api/teacher-duty/occurrence-categories',
            [
                'school_id' => (string) $foreignSchool->id,
                'code' => 'foreign_school_probe',
                'name' => 'Foreign School Probe',
                'description' => null,
                'display_order' => 96,
            ]
        );

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'school_id',
            ]);

        $this->assertDatabaseMissing(
            'teacher_duty_occurrence_categories',
            [
                'school_id' => $foreignSchool->id,
                'code' => 'foreign_school_probe',
            ]
        );
    }

    public function test_category_deactivation_rejects_client_lifecycle_fields(): void
    {
        $category = app(TeacherDutyOccurrenceService::class)
            ->createCustomCategory(
                (string) $this->school->id,
                'deactivation_probe',
                'Deactivation Probe',
                null,
                97,
                (string) $this->administrator->id
            );

        $this->actingAsJwt($this->administrator);

        $response = $this->patchJson(
            "/api/teacher-duty/occurrence-categories/{$category->id}/deactivate",
            [
                'active' => true,
                'deactivated_by' => (string) $this->reporter->id,
                'deactivated_at' => now()->subDay()->toISOString(),
                'name' => 'Client Renamed Category',
                'display_order' => 999,
            ]
        );

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'active',
                'deactivated_by',
                'deactivated_at',
                'name',
                'display_order',
            ]);

        $this->assertDatabaseHas(
            'teacher_duty_occurrence_categories',
            [
                'id' => $category->id,
                'school_id' => $this->school->id,
                'name' => 'Deactivation Probe',
                'display_order' => 97,
                'active' => true,
                'deactivated_by' => null,
            ]
        );
    }

    public function test_malformed_category_route_identifier_fails_as_generic_422_without_database_leak(): void
    {
        $this->actingAsJwt($this->administrator);

        $response = $this->patchJson(
            '/api/teacher-duty/occurrence-categories/not-a-uuid/deactivate',
            []
        );

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'resource',
            ]);

        $body = strtolower($response->getContent());

        foreach ([
            'sqlstate',
            'postgres',
            'invalid input syntax',
            'queryexception',
            'pdoexception',
            'stack trace',
            'vendor/',
        ] as $leak) {
            $this->assertStringNotContainsString(
                $leak,
                $body
            );
        }
    }

    public function test_cross_tenant_period_occurrence_reads_and_store_fail_closed(): void
    {
        $otherSchool = $this->createSchool();
        $this->completeOperationalSetup($otherSchool);

        $otherActor = $this->createUser($otherSchool);

        $this->grantPermission(
            $otherActor,
            'submit_teacher_duty_reports'
        );

        $otherTeacher = $this->ensureTeacherForUser($otherActor);
        $otherPeriod = $this->createDutyPeriod($otherActor);

        $this->createDutyAssignment(
            $otherPeriod,
            $otherTeacher,
            $otherActor
        );

        app(TeacherDutyOccurrenceCategoryProvisioningService::class)
            ->provision($otherSchool);

        $otherCategoryId = (string) DB::table(
            'teacher_duty_occurrence_categories'
        )
            ->where('school_id', $otherSchool->id)
            ->where('code', 'discipline')
            ->value('id');

        $otherOccurrence = app(TeacherDutyOccurrenceService::class)
            ->recordOccurrence(
                (string) $otherSchool->id,
                (string) $otherPeriod->id,
                $otherCategoryId,
                '2026-09-09',
                null,
                'Foreign tenant occurrence.',
                (string) $otherActor->id
            );

        $this->actingAsJwt($this->reporter);

        $listResponse = $this->getJson(
            "/api/teacher-duty/periods/{$otherPeriod->id}/occurrences"
        );

        $listResponse
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'resource',
            ]);

        $showResponse = $this->getJson(
            "/api/teacher-duty/occurrences/{$otherOccurrence->id}"
        );

        $showResponse
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'resource',
            ]);

        $storeResponse = $this->postJson(
            "/api/teacher-duty/periods/{$otherPeriod->id}/occurrences",
            [
                'occurrence_category_id' => $otherCategoryId,
                'occurrence_date' => '2026-09-09',
                'description' => 'Foreign tenant store attempt.',
            ]
        );

        $storeResponse->assertUnprocessable();

        $this->assertDatabaseMissing(
            'teacher_duty_occurrences',
            [
                'school_id' => $otherSchool->id,
                'description' => 'Foreign tenant store attempt.',
            ]
        );

        foreach ([
            $listResponse,
            $showResponse,
            $storeResponse,
        ] as $response) {
            $body = strtolower($response->getContent());

            foreach ([
                'foreign tenant occurrence',
                'sqlstate',
                'postgres',
                'queryexception',
                'pdoexception',
            ] as $leak) {
                $this->assertStringNotContainsString(
                    $leak,
                    $body
                );
            }
        }
    }

    public function test_cross_tenant_category_deactivation_fails_closed(): void
    {
        $otherSchool = $this->createSchool();
        $otherActor = $this->createUser($otherSchool);

        $category = app(TeacherDutyOccurrenceService::class)
            ->createCustomCategory(
                (string) $otherSchool->id,
                'foreign_category',
                'Foreign Category',
                null,
                90,
                (string) $otherActor->id
            );

        $this->actingAsJwt($this->administrator);

        $response = $this->patchJson(
            "/api/teacher-duty/occurrence-categories/{$category->id}/deactivate",
            []
        );

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'resource',
            ]);

        $this->assertDatabaseHas(
            'teacher_duty_occurrence_categories',
            [
                'id' => $category->id,
                'school_id' => $otherSchool->id,
                'active' => true,
                'deactivated_by' => null,
            ]
        );
    }

    public function test_occurrence_reads_remain_available_when_school_is_non_operational(): void
    {
        $teacher = $this->teacherForUser($this->reporter);
        $period = $this->createDutyPeriod($this->reporter);

        $this->createDutyAssignment(
            $period,
            $teacher,
            $this->reporter
        );

        $categoryId = $this->canonicalCategoryId('discipline');

        $occurrence = app(TeacherDutyOccurrenceService::class)
            ->recordOccurrence(
                (string) $this->school->id,
                (string) $period->id,
                $categoryId,
                '2026-09-09',
                null,
                'Readable while non-operational.',
                (string) $this->reporter->id
            );

        $this->makeSchoolNonOperational($this->school);
        $this->actingAsJwt($this->reporter);

        $this->getJson(
            '/api/teacher-duty/occurrence-categories'
        )->assertOk();

        $this->getJson(
            "/api/teacher-duty/periods/{$period->id}/occurrences"
        )
            ->assertOk()
            ->assertJsonPath(
                'data.0.id',
                (string) $occurrence->id
            );

        $this->getJson(
            "/api/teacher-duty/occurrences/{$occurrence->id}"
        )
            ->assertOk()
            ->assertJsonPath(
                'data.id',
                (string) $occurrence->id
            );
    }

    public function test_occurrence_recording_is_blocked_when_school_is_non_operational(): void
    {
        $teacher = $this->teacherForUser($this->reporter);
        $period = $this->createDutyPeriod($this->reporter);

        $this->createDutyAssignment(
            $period,
            $teacher,
            $this->reporter
        );

        $categoryId = $this->canonicalCategoryId('discipline');

        $this->makeSchoolNonOperational($this->school);
        $this->actingAsJwt($this->reporter);

        $response = $this->postJson(
            "/api/teacher-duty/periods/{$period->id}/occurrences",
            [
                'occurrence_category_id' => $categoryId,
                'occurrence_date' => '2026-09-09',
                'description' => 'Blocked non-operational occurrence.',
            ]
        );

        $this->assertFalse(
            $response->isSuccessful(),
            'Occurrence recording must be blocked for a non-operational school.'
        );

        $this->assertDatabaseMissing(
            'teacher_duty_occurrences',
            [
                'school_id' => $this->school->id,
                'description' => 'Blocked non-operational occurrence.',
            ]
        );
    }

    public function test_category_creation_is_blocked_when_school_is_non_operational(): void
    {
        $this->makeSchoolNonOperational($this->school);
        $this->actingAsJwt($this->administrator);

        $response = $this->postJson(
            '/api/teacher-duty/occurrence-categories',
            [
                'code' => 'blocked_category',
                'name' => 'Blocked Category',
                'description' => null,
                'display_order' => 98,
            ]
        );

        $this->assertFalse(
            $response->isSuccessful(),
            'Category creation must be blocked for a non-operational school.'
        );

        $this->assertDatabaseMissing(
            'teacher_duty_occurrence_categories',
            [
                'school_id' => $this->school->id,
                'code' => 'blocked_category',
            ]
        );
    }

    public function test_category_deactivation_is_blocked_when_school_is_non_operational(): void
    {
        $category = app(TeacherDutyOccurrenceService::class)
            ->createCustomCategory(
                (string) $this->school->id,
                'deactivation_block_probe',
                'Deactivation Block Probe',
                null,
                99,
                (string) $this->administrator->id
            );

        $this->makeSchoolNonOperational($this->school);
        $this->actingAsJwt($this->administrator);

        $response = $this->patchJson(
            "/api/teacher-duty/occurrence-categories/{$category->id}/deactivate",
            []
        );

        $this->assertFalse(
            $response->isSuccessful(),
            'Category deactivation must be blocked for a non-operational school.'
        );

        $this->assertDatabaseHas(
            'teacher_duty_occurrence_categories',
            [
                'id' => $category->id,
                'school_id' => $this->school->id,
                'active' => true,
                'deactivated_by' => null,
            ]
        );
    }

    public function test_occurrence_http_responses_expose_only_frozen_fields(): void
    {
        $teacher = $this->teacherForUser($this->reporter);
        $period = $this->createDutyPeriod($this->reporter);

        $this->createDutyAssignment(
            $period,
            $teacher,
            $this->reporter
        );

        $categoryId = $this->canonicalCategoryId('discipline');

        $this->actingAsJwt($this->reporter);

        $occurrenceResponse = $this->postJson(
            "/api/teacher-duty/periods/{$period->id}/occurrences",
            [
                'occurrence_category_id' => $categoryId,
                'occurrence_date' => '2026-09-09',
                'occurrence_time' => '10:15',
                'description' => 'Response allowlist evidence.',
            ]
        )->assertCreated();

        $occurrenceId = (string) $occurrenceResponse->json('data.id');

        $expectedOccurrenceKeys = [
            'id',
            'duty_period_id',
            'occurrence_category_id',
            'occurrence_date',
            'occurrence_time',
            'description',
            'recorded_by',
            'created_at',
            'updated_at',
        ];

        $occurrenceData = $occurrenceResponse->json('data');

        $this->assertIsArray($occurrenceData);

        $this->assertSame(
            $expectedOccurrenceKeys,
            array_keys($occurrenceData)
        );

        $this->actingAsJwt($this->reporter);

        $showData = $this->getJson(
            "/api/teacher-duty/occurrences/{$occurrenceId}"
        )
            ->assertOk()
            ->json('data');

        $this->assertIsArray($showData);

        $this->assertSame(
            $expectedOccurrenceKeys,
            array_keys($showData)
        );

        $this->actingAsJwt($this->reporter);

        $indexData = $this->getJson(
            "/api/teacher-duty/periods/{$period->id}/occurrences"
        )
            ->assertOk()
            ->json('data.0');

        $this->assertIsArray($indexData);

        $this->assertSame(
            $expectedOccurrenceKeys,
            array_keys($indexData)
        );

        $this->actingAsJwt($this->administrator);

        $categoryResponse = $this->postJson(
            '/api/teacher-duty/occurrence-categories',
            [
                'code' => 'response_allowlist',
                'name' => 'Response Allowlist',
                'description' => 'Response allowlist evidence.',
                'display_order' => 98,
            ]
        )->assertCreated();

        $customCategoryId = (string) $categoryResponse->json('data.id');

        $expectedCategoryKeys = [
            'id',
            'code',
            'name',
            'description',
            'is_canonical',
            'display_order',
            'active',
            'created_by',
            'deactivated_by',
            'deactivated_at',
            'created_at',
            'updated_at',
        ];

        $categoryData = $categoryResponse->json('data');

        $this->assertIsArray($categoryData);

        $this->assertSame(
            $expectedCategoryKeys,
            array_keys($categoryData)
        );

        $this->actingAsJwt($this->reporter);

        $listedCategories = $this->getJson(
            '/api/teacher-duty/occurrence-categories'
        )
            ->assertOk()
            ->json('data');

        $listedCategory = collect($listedCategories)->first(
            fn (array $category) => ($category['id'] ?? null) === $customCategoryId
        );

        $this->assertIsArray($listedCategory);

        $this->assertSame(
            $expectedCategoryKeys,
            array_keys($listedCategory)
        );

        $this->actingAsJwt($this->administrator);

        $deactivatedData = $this->patchJson(
            "/api/teacher-duty/occurrence-categories/{$customCategoryId}/deactivate",
            []
        )
            ->assertOk()
            ->json('data');

        $this->assertIsArray($deactivatedData);

        $this->assertSame(
            $expectedCategoryKeys,
            array_keys($deactivatedData)
        );

        foreach ([
            $occurrenceData,
            $showData,
            $indexData,
            $categoryData,
            $listedCategory,
            $deactivatedData,
        ] as $responseData) {
            $this->assertArrayNotHasKey(
                'school_id',
                $responseData
            );
        }
    }

    public function test_successful_occurrence_mutations_create_authoritative_audit_records(): void
    {
        $teacher = $this->teacherForUser($this->reporter);
        $period = $this->createDutyPeriod($this->reporter);

        $this->createDutyAssignment(
            $period,
            $teacher,
            $this->reporter
        );

        $categoryId = $this->canonicalCategoryId('discipline');

        $this->actingAsJwt($this->reporter);

        $occurrenceResponse = $this->postJson(
            "/api/teacher-duty/periods/{$period->id}/occurrences",
            [
                'occurrence_category_id' => $categoryId,
                'occurrence_date' => '2026-09-09',
                'occurrence_time' => '10:15',
                'description' => 'Audit occurrence evidence.',
            ]
        )->assertCreated();

        $occurrenceId = (string) $occurrenceResponse->json('data.id');

        $this->assertDatabaseHas(
            'audit_logs',
            [
                'school_id' => $this->school->id,
                'user_id' => $this->reporter->id,
                'module' => 'Teacher Duty Occurrences',
                'action' => 'Create',
                'table_name' => 'teacher_duty_occurrences',
                'record_id' => $occurrenceId,
            ]
        );

        $this->actingAsJwt($this->administrator);

        $categoryResponse = $this->postJson(
            '/api/teacher-duty/occurrence-categories',
            [
                'code' => 'audit_category',
                'name' => 'Audit Category',
                'description' => 'Audit category evidence.',
                'display_order' => 97,
            ]
        )->assertCreated();

        $customCategoryId = (string) $categoryResponse->json('data.id');

        $this->assertDatabaseHas(
            'audit_logs',
            [
                'school_id' => $this->school->id,
                'user_id' => $this->administrator->id,
                'module' => 'Teacher Duty Occurrence Categories',
                'action' => 'Create',
                'table_name' => 'teacher_duty_occurrence_categories',
                'record_id' => $customCategoryId,
            ]
        );

        $this->actingAsJwt($this->administrator);

        $this->patchJson(
            "/api/teacher-duty/occurrence-categories/{$customCategoryId}/deactivate",
            []
        )->assertOk();

        $this->assertDatabaseHas(
            'audit_logs',
            [
                'school_id' => $this->school->id,
                'user_id' => $this->administrator->id,
                'module' => 'Teacher Duty Occurrence Categories',
                'action' => 'Deactivate',
                'table_name' => 'teacher_duty_occurrence_categories',
                'record_id' => $customCategoryId,
            ]
        );

        $audits = DB::table('audit_logs')
            ->where('school_id', $this->school->id)
            ->whereIn('module', [
                'Teacher Duty Occurrences',
                'Teacher Duty Occurrence Categories',
            ])
            ->whereIn('record_id', [
                $occurrenceId,
                $customCategoryId,
            ])
            ->orderBy('created_at')
            ->get();

        $this->assertCount(3, $audits);

        foreach ($audits as $audit) {
            $newValues = json_decode(
                (string) $audit->new_values,
                true
            );

            $this->assertIsArray($newValues);

            foreach ([
                'id',
                'school_id',
                'recorded_by',
                'created_by',
                'deactivated_by',
                'created_at',
                'updated_at',
            ] as $authorityField) {
                $this->assertArrayNotHasKey(
                    $authorityField,
                    $newValues
                );
            }
        }

        $occurrenceAudit = $audits->first(
            fn ($audit) => $audit->table_name === 'teacher_duty_occurrences'
        );

        $this->assertNotNull($occurrenceAudit);

        $occurrenceValues = json_decode(
            (string) $occurrenceAudit->new_values,
            true
        );

        $this->assertSame(
            (string) $period->id,
            $occurrenceValues['duty_period_id'] ?? null
        );

        $this->assertSame(
            $categoryId,
            $occurrenceValues['occurrence_category_id'] ?? null
        );

        $this->assertSame(
            '2026-09-09',
            $occurrenceValues['occurrence_date'] ?? null
        );

        $this->assertSame(
            'Audit occurrence evidence.',
            $occurrenceValues['description'] ?? null
        );

        $categoryAudits = $audits
            ->where(
                'table_name',
                'teacher_duty_occurrence_categories'
            )
            ->values();

        $this->assertCount(2, $categoryAudits);

        $createdValues = json_decode(
            (string) $categoryAudits[0]->new_values,
            true
        );

        $deactivatedValues = json_decode(
            (string) $categoryAudits[1]->new_values,
            true
        );

        $this->assertSame(
            'audit_category',
            $createdValues['code'] ?? null
        );

        $this->assertSame(
            'Audit Category',
            $createdValues['name'] ?? null
        );

        $this->assertTrue(
            $createdValues['active'] ?? false
        );

        $this->assertFalse(
            $deactivatedValues['active'] ?? true
        );

        $this->assertArrayHasKey(
            'deactivated_at',
            $deactivatedValues
        );

        $this->assertNotNull(
            $deactivatedValues['deactivated_at']
        );
    }

    public function test_forbidden_occurrence_mutation_routes_are_not_registered(): void
    {
        $routes = collect(Route::getRoutes()->getRoutes());

        foreach ([
            ['PUT', 'api/teacher-duty/occurrences/{occurrence}'],
            ['PATCH', 'api/teacher-duty/occurrences/{occurrence}'],
            ['DELETE', 'api/teacher-duty/occurrences/{occurrence}'],
            [
                'PATCH',
                'api/teacher-duty/occurrence-categories/{category}',
            ],
            [
                'DELETE',
                'api/teacher-duty/occurrence-categories/{category}',
            ],
            [
                'PATCH',
                'api/teacher-duty/occurrence-categories/{category}/reactivate',
            ],
        ] as [$method, $uri]) {
            $route = $routes->first(
                fn ($candidate): bool => in_array(
                    $method,
                    $candidate->methods(),
                    true
                ) && $candidate->uri() === $uri
            );

            $this->assertNull(
                $route,
                "{$method} {$uri} must not be registered."
            );
        }
    }

    private function actingAsJwt(User $user): void
    {
        $token = JWTAuth::fromUser($user);

        $this->withHeaders([
            'Authorization' => "Bearer {$token}",
        ]);
    }

    private function ensureTeacherForUser(User $user): Teacher
    {
        $teacher = Teacher::query()
            ->withoutGlobalScopes()
            ->where('school_id', $user->school_id)
            ->where('user_id', $user->id)
            ->first();

        if ($teacher) {
            return $teacher;
        }

        $school = School::query()
            ->withoutGlobalScopes()
            ->whereKey($user->school_id)
            ->firstOrFail();

        TeacherBuilder::create(
            $school,
            $user
        );

        return Teacher::query()
            ->withoutGlobalScopes()
            ->where('school_id', $user->school_id)
            ->where('user_id', $user->id)
            ->where('active', true)
            ->where('is_deleted', false)
            ->firstOrFail();
    }

    private function teacherForUser(User $user): Teacher
    {
        return $this->ensureTeacherForUser($user);
    }

    private function canonicalCategoryId(
        string $code
    ): string {
        app(TeacherDutyOccurrenceCategoryProvisioningService::class)
            ->provision($this->school);

        $categoryId = DB::table(
            'teacher_duty_occurrence_categories'
        )
            ->where('school_id', $this->school->id)
            ->where('code', $code)
            ->value('id');

        if (! $categoryId) {
            throw new \RuntimeException(
                "Canonical teacher duty occurrence category [{$code}] was not found."
            );
        }

        return (string) $categoryId;
    }

    private function createDutyPeriod(
        User $actor
    ): TeacherDutyPeriod {
        return app(TeacherDutyRosterService::class)
            ->createPeriod(
                (string) $actor->school_id,
                '2026-09-07',
                '2026-09-11',
                null,
                (string) $actor->id
            );
    }

    private function createDutyAssignment(
        TeacherDutyPeriod $period,
        Teacher $teacher,
        User $actor,
        bool $active = true
    ): TeacherDutyAssignment {
        $assignment = app(TeacherDutyRosterService::class)
            ->assignTeacher(
                (string) $actor->school_id,
                (string) $period->id,
                (string) $teacher->id,
                (string) $actor->id
            );

        if (! $active) {
            return app(TeacherDutyRosterService::class)
                ->endAssignment(
                    (string) $actor->school_id,
                    (string) $assignment->id,
                    (string) $actor->id,
                    'Historical assignment'
                );
        }

        return $assignment;
    }

    private function createSchool(): School
    {
        return School::query()->create([
            'id' => (string) Str::uuid(),
            'school_name' => 'Teacher Duty Occurrence HTTP '.Str::upper(
                Str::random(8)
            ),
            'school_code' => 'TDO-'.Str::upper(
                Str::random(8)
            ),
            'short_name' => 'TDO',
            'registration_number' => 'REG-'.Str::upper(
                Str::random(10)
            ),
            'school_type' => 'Primary',
            'county' => 'Nairobi',
            'phone' => '+2547'.random_int(
                10000000,
                99999999
            ),
            'email' => Str::lower(
                Str::random(10)
            ).'@example.test',
            'timezone' => 'Africa/Nairobi',
            'locale' => 'en',
            'active' => true,
        ]);
    }

    private function createUser(School $school): User
    {
        $role = Role::query()->create([
            'id' => (string) Str::uuid(),
            'role_name' => 'Teacher Duty Occurrence HTTP '.Str::upper(
                Str::random(8)
            ),
            'description' => 'Teacher duty occurrence HTTP test role',
            'active' => true,
        ]);

        return User::query()->create([
            'id' => (string) Str::uuid(),
            'school_id' => $school->id,
            'role_id' => $role->id,
            'first_name' => 'Teacher',
            'last_name' => 'Duty Occurrence',
            'username' => 'teacher_duty_occurrence_'.Str::lower(
                Str::random(10)
            ),
            'email' => Str::lower(
                Str::random(10)
            ).'@example.test',
            'password_hash' => bcrypt('Password123!'),
            'active' => true,
            'first_login' => false,
        ]);
    }

    private function completeOperationalSetup(
        School $school
    ): void {
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
            'grade_order' => random_int(
                2001,
                3000
            ),
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

    private function makeSchoolNonOperational(
        School $school
    ): void {
        DB::table('streams')
            ->where('school_id', $school->id)
            ->delete();

        DB::table('terms')
            ->where('school_id', $school->id)
            ->update([
                'active' => false,
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
                'Teacher Duty occurrence HTTP test actor has no primary role.'
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
