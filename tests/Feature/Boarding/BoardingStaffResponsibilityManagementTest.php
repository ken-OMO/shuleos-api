<?php

declare(strict_types=1);

namespace Tests\Feature\Boarding;

use App\Models\Hostel;
use App\Models\Role;
use App\Models\School;
use App\Models\User;
use App\Services\Boarding\BoardingStaffResponsibilityService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Tests\TestCase;

class BoardingStaffResponsibilityManagementTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'jwt.secret' => str_repeat('a', 64),
        ]);
    }

    public function test_responsibility_routes_have_frozen_security_contract(): void
    {
        $routes = collect(Route::getRoutes()->getRoutes());

        $contracts = [
            [
                'GET',
                'api/boarding/hostels/{hostel}/staff-assignments',
                false,
            ],
            [
                'POST',
                'api/boarding/hostels/{hostel}/staff-assignments',
                true,
            ],
            [
                'GET',
                'api/boarding/hostels/{hostel}/staff-assignments/history',
                false,
            ],
            [
                'PATCH',
                'api/boarding/staff-assignments/{assignment}/end',
                false,
            ],
        ];

        foreach ($contracts as [$method, $uri, $operational]) {
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
                'permission:manage_boarding',
                $middleware,
                "{$method} {$uri} must require manage_boarding."
            );

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
                    "{$method} {$uri} must remain outside the operational gate."
                );
            }
        }
    }

    public function test_unauthenticated_user_cannot_use_responsibility_endpoints(): void
    {
        $hostelId = (string) Str::uuid();
        $assignmentId = (string) Str::uuid();

        $this->getJson(
            "/api/boarding/hostels/{$hostelId}/staff-assignments"
        )->assertUnauthorized();

        $this->postJson(
            "/api/boarding/hostels/{$hostelId}/staff-assignments",
            [
                'user_id' => (string) Str::uuid(),
                'responsibility_role' => 'Matron',
            ]
        )->assertUnauthorized();

        $this->getJson(
            "/api/boarding/hostels/{$hostelId}/staff-assignments/history"
        )->assertUnauthorized();

        $this->patchJson(
            "/api/boarding/staff-assignments/{$assignmentId}/end",
            [
                'reason' => 'End attempt.',
            ]
        )->assertUnauthorized();
    }

    public function test_responsibility_does_not_grant_manage_boarding_permission(): void
    {
        $school = $this->school();
        $actor = $this->user($school);
        $responsible = $this->user($school);
        $hostel = $this->hostel($school);

        app(BoardingStaffResponsibilityService::class)->assign(
            (string) $school->id,
            (string) $hostel->id,
            (string) $responsible->id,
            'Matron',
            null,
            (string) $actor->id
        );

        $this->withToken($this->tokenFor($responsible))
            ->getJson(
                "/api/boarding/hostels/{$hostel->id}/staff-assignments"
            )
            ->assertForbidden();

        $this->withToken($this->tokenFor($responsible))
            ->postJson(
                "/api/boarding/hostels/{$hostel->id}/staff-assignments",
                [
                    'user_id' => $actor->id,
                    'responsibility_role' => 'Warden',
                ]
            )
            ->assertForbidden();
    }

    public function test_authorized_user_can_assign_same_tenant_non_teacher_user(): void
    {
        [$school, $actor] = $this->authorizedSchoolUser();
        $responsible = $this->user($school);
        $hostel = $this->hostel($school);

        $this->assertFalse(
            DB::table('teachers')
                ->where('school_id', $school->id)
                ->where('user_id', $responsible->id)
                ->exists()
        );

        $response = $this->withToken($this->tokenFor($actor))
            ->postJson(
                "/api/boarding/hostels/{$hostel->id}/staff-assignments",
                [
                    'user_id' => $responsible->id,
                    'responsibility_role' => 'Matron',
                ]
            )
            ->assertCreated()
            ->assertJsonPath('data.user_id', $responsible->id)
            ->assertJsonPath('data.responsibility_role', 'Matron')
            ->assertJsonPath('data.active', true);

        $assignmentId = (string) $response->json('data.id');

        $this->assertNotSame('', $assignmentId);

        $this->assertDatabaseHas('hostel_staff_assignments', [
            'id' => $assignmentId,
            'school_id' => $school->id,
            'hostel_id' => $hostel->id,
            'user_id' => $responsible->id,
            'responsibility_role' => 'Matron',
            'active' => true,
            'assigned_by' => $actor->id,
            'effective_to' => null,
            'ended_by' => null,
            'ended_at' => null,
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'school_id' => $school->id,
            'user_id' => $actor->id,
            'module' => 'Boarding',
            'action' => 'Create',
            'table_name' => 'hostel_staff_assignments',
            'record_id' => $assignmentId,
        ]);
    }

    public function test_create_requires_operational_school_but_end_does_not(): void
    {
        [$school, $actor] = $this->authorizedSchoolUser();
        $responsible = $this->user($school);
        $hostel = $this->hostel($school);

        $createResponse = $this->withToken($this->tokenFor($actor))
            ->postJson(
                "/api/boarding/hostels/{$hostel->id}/staff-assignments",
                [
                    'user_id' => $responsible->id,
                    'responsibility_role' => 'Warden',
                ]
            )
            ->assertCreated();

        $assignmentId = (string) $createResponse->json('data.id');

        $this->makeSchoolNonOperational($school);

        $blocked = $this->withToken($this->tokenFor($actor))
            ->postJson(
                "/api/boarding/hostels/{$hostel->id}/staff-assignments",
                [
                    'user_id' => $this->user($school)->id,
                    'responsibility_role' => 'Matron',
                ]
            );

        $this->assertFalse(
            $blocked->isSuccessful(),
            'Creation must be rejected when school is non-operational.'
        );

        $this->withToken($this->tokenFor($actor))
            ->patchJson(
                "/api/boarding/staff-assignments/{$assignmentId}/end",
                [
                    'reason' => 'Responsibility concluded.',
                ]
            )
            ->assertOk()
            ->assertJsonPath('data.active', false);

        $this->assertDatabaseHas('hostel_staff_assignments', [
            'id' => $assignmentId,
            'school_id' => $school->id,
            'active' => false,
            'ended_by' => $actor->id,
            'end_reason' => 'Responsibility concluded.',
        ]);
    }

    public function test_cross_tenant_user_and_hostel_are_rejected_without_leakage(): void
    {
        [$schoolA, $actorA] = $this->authorizedSchoolUser();

        $schoolB = $this->school();
        $foreignUser = $this->user($schoolB);
        $foreignHostel = $this->hostel($schoolB);

        $localHostel = $this->hostel($schoolA);
        $localUser = $this->user($schoolA);

        $beforeA = $this->assignmentCount($schoolA);
        $beforeB = $this->assignmentCount($schoolB);

        $this->withToken($this->tokenFor($actorA))
            ->postJson(
                "/api/boarding/hostels/{$localHostel->id}/staff-assignments",
                [
                    'user_id' => $foreignUser->id,
                    'responsibility_role' => 'Matron',
                ]
            )
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['user_id']);

        $this->withToken($this->tokenFor($actorA))
            ->postJson(
                "/api/boarding/hostels/{$foreignHostel->id}/staff-assignments",
                [
                    'user_id' => $localUser->id,
                    'responsibility_role' => 'Warden',
                ]
            )
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['hostel_id']);

        $this->assertSame($beforeA, $this->assignmentCount($schoolA));
        $this->assertSame($beforeB, $this->assignmentCount($schoolB));
    }

    public function test_inactive_deleted_and_suspended_users_are_rejected(): void
    {
        [$school, $actor] = $this->authorizedSchoolUser();
        $hostel = $this->hostel($school);

        $cases = [
            ['active' => false],
            ['is_deleted' => true],
            ['suspended_at' => now()],
        ];

        foreach ($cases as $state) {
            $responsible = $this->user($school);

            DB::table('users')
                ->where('id', $responsible->id)
                ->where('school_id', $school->id)
                ->update($state);

            $response = $this->withToken($this->tokenFor($actor))
                ->postJson(
                    "/api/boarding/hostels/{$hostel->id}/staff-assignments",
                    [
                        'user_id' => $responsible->id,
                        'responsibility_role' => 'Matron',
                    ]
                );

            $response
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['user_id']);
        }

        $this->assertSame(0, $this->assignmentCount($school));
    }

    public function test_inactive_and_deleted_hostels_are_rejected(): void
    {
        [$school, $actor] = $this->authorizedSchoolUser();
        $responsible = $this->user($school);

        foreach (
            [
                ['active' => false],
                ['is_deleted' => true],
            ] as $state
        ) {
            $hostel = $this->hostel($school);

            DB::table('hostels')
                ->where('id', $hostel->id)
                ->where('school_id', $school->id)
                ->update($state);

            $this->withToken($this->tokenFor($actor))
                ->postJson(
                    "/api/boarding/hostels/{$hostel->id}/staff-assignments",
                    [
                        'user_id' => $responsible->id,
                        'responsibility_role' => 'Matron',
                    ]
                )
                ->assertUnprocessable();
        }

        $this->assertSame(0, $this->assignmentCount($school));
    }

    public function test_future_effective_from_is_rejected_using_school_local_date(): void
    {
        [$school, $actor] = $this->authorizedSchoolUser();
        $responsible = $this->user($school);
        $hostel = $this->hostel($school);

        CarbonImmutable::setTestNow(
            CarbonImmutable::parse('2026-09-06 12:00:00', 'Africa/Nairobi')
        );

        try {
            $this->withToken($this->tokenFor($actor))
                ->postJson(
                    "/api/boarding/hostels/{$hostel->id}/staff-assignments",
                    [
                        'user_id' => $responsible->id,
                        'responsibility_role' => 'Matron',
                        'effective_from' => '2026-09-07',
                    ]
                )
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['effective_from']);

            $this->withToken($this->tokenFor($actor))
                ->postJson(
                    "/api/boarding/hostels/{$hostel->id}/staff-assignments",
                    [
                        'user_id' => $responsible->id,
                        'responsibility_role' => 'Matron',
                        'effective_from' => '2026-09-06',
                    ]
                )
                ->assertCreated()
                ->assertJsonPath('data.effective_from', '2026-09-06');
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_duplicate_current_identity_is_domain_conflict_not_raw_sql_error(): void
    {
        [$school, $actor] = $this->authorizedSchoolUser();
        $responsible = $this->user($school);
        $hostel = $this->hostel($school);

        $payload = [
            'user_id' => $responsible->id,
            'responsibility_role' => 'Matron',
        ];

        $this->withToken($this->tokenFor($actor))
            ->postJson(
                "/api/boarding/hostels/{$hostel->id}/staff-assignments",
                $payload
            )
            ->assertCreated();

        $response = $this->withToken($this->tokenFor($actor))
            ->postJson(
                "/api/boarding/hostels/{$hostel->id}/staff-assignments",
                $payload
            )
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['responsibility_role']);

        $body = Str::lower($response->getContent());

        $this->assertStringNotContainsString(
            'sqlstate',
            $body
        );

        $this->assertStringNotContainsString(
            'hostel_staff_assignments_active_identity_unique',
            $body
        );

        $this->assertSame(1, $this->assignmentCount($school));
    }

    public function test_multiple_managers_multiple_hostels_and_distinct_roles_are_allowed(): void
    {
        [$school, $actor] = $this->authorizedSchoolUser();

        $userA = $this->user($school);
        $userB = $this->user($school);

        $hostelA = $this->hostel($school, 'GIRLS');
        $hostelB = $this->hostel($school, 'BOYS');

        $token = $this->tokenFor($actor);

        foreach (
            [
                [$hostelA, $userA, 'Matron'],
                [$hostelA, $userB, 'Assistant Matron'],
                [$hostelB, $userA, 'Warden'],
                [$hostelA, $userA, 'Boarding Master'],
            ] as [$hostel, $user, $role]
        ) {
            $this->withToken($token)
                ->postJson(
                    "/api/boarding/hostels/{$hostel->id}/staff-assignments",
                    [
                        'user_id' => $user->id,
                        'responsibility_role' => $role,
                    ]
                )
                ->assertCreated();
        }

        $this->assertSame(4, $this->assignmentCount($school));
    }

    public function test_server_owned_fields_are_rejected_on_create_and_end(): void
    {
        [$school, $actor] = $this->authorizedSchoolUser();
        $responsible = $this->user($school);
        $hostel = $this->hostel($school);

        $forbiddenCreateFields = [
            'school_id',
            'assigned_by',
            'ended_by',
            'ended_at',
            'effective_to',
            'active',
            'created_at',
            'updated_at',
        ];

        foreach ($forbiddenCreateFields as $field) {
            $payload = [
                'user_id' => $responsible->id,
                'responsibility_role' => 'Matron',
                $field => $field === 'active'
                    ? false
                    : 'attacker-controlled',
            ];

            $response = $this->withToken($this->tokenFor($actor))
                ->postJson(
                    "/api/boarding/hostels/{$hostel->id}/staff-assignments",
                    $payload
                );

            $this->assertSame(
                422,
                $response->status(),
                "Server-owned create field [{$field}] must be rejected. Response: "
                    .$response->getContent()
            );

            $response->assertJsonValidationErrors([$field]);
        }

        $assignment = app(
            BoardingStaffResponsibilityService::class
        )->assign(
            (string) $school->id,
            (string) $hostel->id,
            (string) $responsible->id,
            'Matron',
            null,
            (string) $actor->id
        );

        foreach (
            [
                'school_id',
                'assigned_by',
                'ended_by',
                'ended_at',
                'effective_to',
                'active',
                'created_at',
                'updated_at',
            ] as $field
        ) {
            $this->withToken($this->tokenFor($actor))
                ->patchJson(
                    "/api/boarding/staff-assignments/{$assignment->id}/end",
                    [
                        'reason' => 'Attempted lifecycle closure.',
                        $field => $field === 'active'
                            ? true
                            : 'attacker-controlled',
                    ]
                )
                ->assertUnprocessable()
                ->assertJsonValidationErrors([$field]);
        }

        $this->assertDatabaseHas('hostel_staff_assignments', [
            'id' => $assignment->id,
            'school_id' => $school->id,
            'active' => true,
            'effective_to' => null,
            'ended_by' => null,
            'ended_at' => null,
        ]);
    }

    public function test_end_is_terminal_history_is_preserved_and_new_episode_is_allowed(): void
    {
        [$school, $actor] = $this->authorizedSchoolUser();
        $responsible = $this->user($school);
        $hostel = $this->hostel($school);

        $token = $this->tokenFor($actor);

        $create = $this->withToken($token)
            ->postJson(
                "/api/boarding/hostels/{$hostel->id}/staff-assignments",
                [
                    'user_id' => $responsible->id,
                    'responsibility_role' => 'Matron',
                ]
            )
            ->assertCreated();

        $firstId = (string) $create->json('data.id');

        $this->withToken($token)
            ->patchJson(
                "/api/boarding/staff-assignments/{$firstId}/end",
                [
                    'reason' => 'Rotation completed.',
                ]
            )
            ->assertOk()
            ->assertJsonPath('data.active', false);

        $this->withToken($token)
            ->patchJson(
                "/api/boarding/staff-assignments/{$firstId}/end",
                [
                    'reason' => 'Illegal second ending.',
                ]
            )
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['assignment']);

        $current = $this->withToken($token)
            ->getJson(
                "/api/boarding/hostels/{$hostel->id}/staff-assignments"
            )
            ->assertOk();

        $this->assertSame([], $current->json('data'));

        $history = $this->withToken($token)
            ->getJson(
                "/api/boarding/hostels/{$hostel->id}/staff-assignments/history"
            )
            ->assertOk();

        $historyIds = collect($history->json('data'))
            ->pluck('id')
            ->all();

        $this->assertContains($firstId, $historyIds);

        $second = $this->withToken($token)
            ->postJson(
                "/api/boarding/hostels/{$hostel->id}/staff-assignments",
                [
                    'user_id' => $responsible->id,
                    'responsibility_role' => 'Matron',
                ]
            )
            ->assertCreated();

        $secondId = (string) $second->json('data.id');

        $this->assertNotSame($firstId, $secondId);

        $this->assertDatabaseHas('hostel_staff_assignments', [
            'id' => $firstId,
            'active' => false,
            'end_reason' => 'Rotation completed.',
        ]);

        $this->assertDatabaseHas('hostel_staff_assignments', [
            'id' => $secondId,
            'active' => true,
        ]);

        $this->assertSame(2, $this->assignmentCount($school));
    }

    public function test_cross_tenant_reads_and_end_fail_closed(): void
    {
        [$schoolA, $actorA] = $this->authorizedSchoolUser();

        $responsibleA = $this->user($schoolA);
        $hostelA = $this->hostel($schoolA);

        $assignment = app(
            BoardingStaffResponsibilityService::class
        )->assign(
            (string) $schoolA->id,
            (string) $hostelA->id,
            (string) $responsibleA->id,
            'Matron',
            null,
            (string) $actorA->id
        );

        [$schoolB, $actorB] = $this->authorizedSchoolUser();

        $tokenB = $this->tokenFor($actorB);

        $this->withToken($tokenB)
            ->getJson(
                "/api/boarding/hostels/{$hostelA->id}/staff-assignments"
            )
            ->assertNotFound();

        $this->withToken($tokenB)
            ->getJson(
                "/api/boarding/hostels/{$hostelA->id}/staff-assignments/history"
            )
            ->assertNotFound();

        $this->withToken($tokenB)
            ->patchJson(
                "/api/boarding/staff-assignments/{$assignment->id}/end",
                [
                    'reason' => 'Foreign tenant attack.',
                ]
            )
            ->assertNotFound();

        $this->assertDatabaseHas('hostel_staff_assignments', [
            'id' => $assignment->id,
            'school_id' => $schoolA->id,
            'active' => true,
            'ended_by' => null,
            'ended_at' => null,
        ]);

        $this->assertSame(0, $this->assignmentCount($schoolB));
    }

    public function test_public_json_hides_tenant_actor_and_security_sensitive_fields(): void
    {
        [$school, $actor] = $this->authorizedSchoolUser();
        $responsible = $this->user($school);
        $hostel = $this->hostel($school);

        $response = $this->withToken($this->tokenFor($actor))
            ->postJson(
                "/api/boarding/hostels/{$hostel->id}/staff-assignments",
                [
                    'user_id' => $responsible->id,
                    'responsibility_role' => 'Matron',
                ]
            )
            ->assertCreated();

        $data = $response->json('data');

        $this->assertIsArray($data);

        foreach (
            [
                'school_id',
                'assigned_by',
                'ended_by',
                'password',
                'password_hash',
                'remember_token',
                'suspended_at',
                'is_deleted',
                'deleted_at',
            ] as $forbidden
        ) {
            $this->assertArrayNotHasKey($forbidden, $data);
        }

        $json = Str::lower($response->getContent());

        $this->assertStringNotContainsString(
            'password_hash',
            $json
        );

        $this->assertStringNotContainsString(
            'remember_token',
            $json
        );
    }

    public function test_end_reason_boundary_is_enforced(): void
    {
        [$school, $actor] = $this->authorizedSchoolUser();
        $responsible = $this->user($school);
        $hostel = $this->hostel($school);

        $assignment = app(
            BoardingStaffResponsibilityService::class
        )->assign(
            (string) $school->id,
            (string) $hostel->id,
            (string) $responsible->id,
            'Matron',
            null,
            (string) $actor->id
        );

        $token = $this->tokenFor($actor);

        $this->withToken($token)
            ->patchJson(
                "/api/boarding/staff-assignments/{$assignment->id}/end",
                [
                    'reason' => str_repeat('R', 501),
                ]
            )
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['reason']);

        $this->assertDatabaseHas('hostel_staff_assignments', [
            'id' => $assignment->id,
            'active' => true,
            'end_reason' => null,
        ]);

        $reason = str_repeat('R', 500);

        $this->withToken($token)
            ->patchJson(
                "/api/boarding/staff-assignments/{$assignment->id}/end",
                [
                    'reason' => $reason,
                ]
            )
            ->assertOk()
            ->assertJsonPath('data.active', false);

        $this->assertDatabaseHas('hostel_staff_assignments', [
            'id' => $assignment->id,
            'active' => false,
            'end_reason' => $reason,
            'ended_by' => $actor->id,
        ]);
    }

    private function authorizedSchoolUser(
        bool $operational = true
    ): array {
        $school = $this->school();
        $user = $this->user($school);

        if ($operational) {
            $this->completeOperationalSetup($school);
        }

        $this->grantManageBoarding($user);

        return [$school, $user];
    }

    private function grantManageBoarding(User $user): void
    {
        $permissionId = DB::table('permissions')
            ->where('permission_name', 'manage_boarding')
            ->value('id');

        $this->assertNotNull(
            $permissionId,
            'manage_boarding must be provisioned by production migration.'
        );

        DB::table('role_permissions')->insertOrIgnore([
            'id' => (string) Str::uuid(),
            'role_id' => $user->role_id,
            'permission_id' => $permissionId,
            'created_at' => now(),
        ]);
    }

    private function school(): School
    {
        return School::query()->create([
            'id' => (string) Str::uuid(),
            'school_name' => 'Responsibility HTTP '.Str::upper(
                Str::random(8)
            ),
            'school_code' => 'RSH-'.Str::upper(
                Str::random(8)
            ),
            'short_name' => 'RSH',
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

    private function user(School $school): User
    {
        $role = Role::query()->create([
            'id' => (string) Str::uuid(),
            'role_name' => 'Responsibility HTTP '.Str::upper(
                Str::random(8)
            ),
            'description' => 'Boarding responsibility HTTP test role',
            'active' => true,
        ]);

        return User::query()->create([
            'id' => (string) Str::uuid(),
            'school_id' => $school->id,
            'role_id' => $role->id,
            'first_name' => 'Boarding',
            'last_name' => 'Responsibility',
            'username' => 'responsibility_http_'.Str::lower(
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

    private function hostel(
        School $school,
        string $type = 'GIRLS'
    ): Hostel {
        $hostel = new Hostel;

        $hostel->school_id = $school->id;
        $hostel->hostel_name = 'Responsibility HTTP Hostel '
            .Str::upper(Str::random(8));
        $hostel->hostel_type = $type;
        $hostel->capacity = 20;
        $hostel->active = true;
        $hostel->is_deleted = false;

        $hostel->save();

        return $hostel->refresh();
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
                1001,
                2000
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

    private function tokenFor(User $user): string
    {
        return JWTAuth::fromUser(
            User::query()
                ->withoutGlobalScopes()
                ->findOrFail($user->id)
        );
    }

    private function assignmentCount(School $school): int
    {
        return DB::table('hostel_staff_assignments')
            ->where('school_id', $school->id)
            ->count();
    }
}
