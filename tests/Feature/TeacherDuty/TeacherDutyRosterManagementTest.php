<?php

declare(strict_types=1);

namespace Tests\Feature\TeacherDuty;

use App\Models\Role;
use App\Models\School;
use App\Models\Teacher;
use App\Models\TeacherDutyAssignment;
use App\Models\TeacherDutyPeriod;
use App\Models\User;
use App\Services\TeacherDuty\TeacherDutyRosterService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Tests\TestCase;

final class TeacherDutyRosterManagementTest extends TestCase
{
    use DatabaseTransactions;

    private School $school;

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'jwt.secret' => str_repeat('a', 64),
        ]);

        $this->school = $this->createSchool();
        $this->completeOperationalSetup($this->school);
        $this->actor = $this->createUser($this->school);

        $this->grantPermission(
            $this->actor,
            'manage_teacher_duty_roster'
        );
    }

    public function test_malformed_route_resource_identifiers_fail_as_generic_422_without_database_leak(): void
    {
        $this->withToken(JWTAuth::fromUser($this->actor));

        foreach ([
            ['GET', '/api/teacher-duty/periods/not-a-uuid'],
            ['PATCH', '/api/teacher-duty/periods/not-a-uuid/end'],
            ['GET', '/api/teacher-duty/periods/not-a-uuid/assignments'],
            ['POST', '/api/teacher-duty/periods/not-a-uuid/assignments'],
            ['GET', '/api/teacher-duty/periods/not-a-uuid/assignments/history'],
            ['GET', '/api/teacher-duty/assignments/not-a-uuid'],
            ['PATCH', '/api/teacher-duty/assignments/not-a-uuid/end'],
        ] as [$method, $uri]) {
            $response = match ($method) {
                'GET' => $this->getJson($uri),
                'POST' => $this->postJson($uri, [
                    'teacher_id' => (string) Str::uuid(),
                ]),
                'PATCH' => $this->patchJson($uri, [
                    'reason' => 'Malformed identifier probe',
                ]),
            };

            $response
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['resource']);

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
                $this->assertStringNotContainsString($leak, $body);
            }
        }
    }

    public function test_routes_have_frozen_security_contract(): void
    {
        $routes = collect(Route::getRoutes()->getRoutes());

        $contracts = [
            ['GET', 'api/teacher-duty/periods', false],
            ['POST', 'api/teacher-duty/periods', true],
            ['GET', 'api/teacher-duty/periods/history', false],
            ['GET', 'api/teacher-duty/periods/{period}', false],
            ['PATCH', 'api/teacher-duty/periods/{period}/end', false],
            ['GET', 'api/teacher-duty/periods/{period}/assignments', false],
            ['POST', 'api/teacher-duty/periods/{period}/assignments', true],
            ['GET', 'api/teacher-duty/periods/{period}/assignments/history', false],
            ['GET', 'api/teacher-duty/assignments/{assignment}', false],
            ['PATCH', 'api/teacher-duty/assignments/{assignment}/end', false],
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
                'permission:manage_teacher_duty_roster',
                $middleware,
                "{$method} {$uri} must require manage_teacher_duty_roster."
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

    public function test_all_routes_require_roster_permission(): void
    {
        $unauthorized = $this->createUser($this->school);

        $period = $this->createPeriod($this->actor);
        $teacher = $this->createTeacher($this->school);
        $assignment = $this->createAssignment(
            $period,
            $teacher,
            $this->actor
        );

        $requests = [
            ['GET', '/api/teacher-duty/periods', []],
            ['POST', '/api/teacher-duty/periods', [
                'start_date' => '2026-09-07',
                'end_date' => '2026-09-11',
            ]],
            ['GET', '/api/teacher-duty/periods/history', []],
            ['GET', "/api/teacher-duty/periods/{$period->id}", []],
            ['PATCH', "/api/teacher-duty/periods/{$period->id}/end", []],
            ['GET', "/api/teacher-duty/periods/{$period->id}/assignments", []],
            ['POST', "/api/teacher-duty/periods/{$period->id}/assignments", [
                'teacher_id' => $teacher->id,
            ]],
            ['GET', "/api/teacher-duty/periods/{$period->id}/assignments/history", []],
            ['GET', "/api/teacher-duty/assignments/{$assignment->id}", []],
            ['PATCH', "/api/teacher-duty/assignments/{$assignment->id}/end", []],
        ];

        foreach ($requests as [$method, $uri, $payload]) {
            $this->withToken(JWTAuth::fromUser($unauthorized));

            $this->json($method, $uri, $payload)
                ->assertForbidden();
        }
    }

    public function test_period_can_be_created_and_server_fields_are_not_exposed(): void
    {
        $this->withToken(JWTAuth::fromUser($this->actor));

        $response = $this->postJson(
            '/api/teacher-duty/periods',
            [
                'start_date' => '2026-09-07',
                'end_date' => '2026-09-11',
            ]
        );

        $response
            ->assertCreated()
            ->assertJsonPath(
                'message',
                'Teacher duty period created successfully.'
            )
            ->assertJsonPath('data.start_date', '2026-09-07')
            ->assertJsonPath('data.end_date', '2026-09-11')
            ->assertJsonPath('data.active', true)
            ->assertJsonMissingPath('data.school_id')
            ->assertJsonMissingPath('data.created_by')
            ->assertJsonMissingPath('data.ended_by');

        $this->assertDatabaseHas('teacher_duty_periods', [
            'id' => $response->json('data.id'),
            'school_id' => $this->school->id,
            'created_by' => $this->actor->id,
            'active' => true,
        ]);
    }

    public function test_client_cannot_control_period_server_fields(): void
    {
        $this->withToken(JWTAuth::fromUser($this->actor));

        $this->postJson('/api/teacher-duty/periods', [
            'start_date' => '2026-09-07',
            'end_date' => '2026-09-11',
            'active' => false,
            'created_by' => (string) Str::uuid(),
            'ended_by' => (string) Str::uuid(),
            'ended_at' => now()->toISOString(),
            'end_reason' => 'client controlled',
        ])->assertUnprocessable();
    }

    public function test_client_cannot_control_school_id(): void
    {
        $foreignSchool = $this->createSchool();

        $this->withToken(JWTAuth::fromUser($this->actor));

        $this->postJson('/api/teacher-duty/periods', [
            'school_id' => $foreignSchool->id,
            'start_date' => '2026-09-07',
            'end_date' => '2026-09-11',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['school_id']);
    }

    public function test_multiple_teachers_can_be_assigned_to_same_period(): void
    {
        $period = $this->createPeriod($this->actor);
        $teacherOne = $this->createTeacher($this->school);
        $teacherTwo = $this->createTeacher($this->school);

        $this->withToken(JWTAuth::fromUser($this->actor));

        $this->postJson(
            "/api/teacher-duty/periods/{$period->id}/assignments",
            ['teacher_id' => $teacherOne->id]
        )->assertCreated();

        $this->postJson(
            "/api/teacher-duty/periods/{$period->id}/assignments",
            ['teacher_id' => $teacherTwo->id]
        )->assertCreated();

        $this->assertDatabaseHas('teacher_duty_assignments', [
            'school_id' => $this->school->id,
            'duty_period_id' => $period->id,
            'teacher_id' => $teacherOne->id,
            'active' => true,
        ]);

        $this->assertDatabaseHas('teacher_duty_assignments', [
            'school_id' => $this->school->id,
            'duty_period_id' => $period->id,
            'teacher_id' => $teacherTwo->id,
            'active' => true,
        ]);
    }

    public function test_assignment_response_hides_authoritative_actor_fields(): void
    {
        $period = $this->createPeriod($this->actor);
        $teacher = $this->createTeacher($this->school);

        $this->withToken(JWTAuth::fromUser($this->actor));

        $response = $this->postJson(
            "/api/teacher-duty/periods/{$period->id}/assignments",
            ['teacher_id' => $teacher->id]
        );

        $response
            ->assertCreated()
            ->assertJsonMissingPath('data.school_id')
            ->assertJsonMissingPath('data.assigned_by')
            ->assertJsonMissingPath('data.ended_by');
    }

    public function test_client_cannot_control_assignment_server_fields(): void
    {
        $period = $this->createPeriod($this->actor);
        $teacher = $this->createTeacher($this->school);

        $this->withToken(JWTAuth::fromUser($this->actor));

        $this->postJson(
            "/api/teacher-duty/periods/{$period->id}/assignments",
            [
                'teacher_id' => $teacher->id,
                'active' => false,
                'assigned_by' => (string) Str::uuid(),
                'ended_by' => (string) Str::uuid(),
                'ended_at' => now()->toISOString(),
                'end_reason' => 'client controlled',
            ]
        )->assertUnprocessable();
    }

    public function test_period_end_is_available_without_operational_middleware(): void
    {
        $period = $this->createPeriod($this->actor);

        $this->withToken(JWTAuth::fromUser($this->actor));

        $this->patchJson(
            "/api/teacher-duty/periods/{$period->id}/end",
            ['reason' => 'Week complete']
        )
            ->assertOk()
            ->assertJsonPath('data.active', false)
            ->assertJsonPath('data.end_reason', 'Week complete');
    }

    public function test_assignment_end_is_available_without_operational_middleware(): void
    {
        $period = $this->createPeriod($this->actor);
        $teacher = $this->createTeacher($this->school);
        $assignment = $this->createAssignment(
            $period,
            $teacher,
            $this->actor
        );

        $this->withToken(JWTAuth::fromUser($this->actor));

        $this->patchJson(
            "/api/teacher-duty/assignments/{$assignment->id}/end",
            ['reason' => 'Duty changed']
        )
            ->assertOk()
            ->assertJsonPath('data.active', false)
            ->assertJsonPath('data.end_reason', 'Duty changed');
    }

    public function test_period_end_closes_current_assignments(): void
    {
        $period = $this->createPeriod($this->actor);
        $teacherOne = $this->createTeacher($this->school);
        $teacherTwo = $this->createTeacher($this->school);

        $assignmentOne = $this->createAssignment(
            $period,
            $teacherOne,
            $this->actor
        );
        $assignmentTwo = $this->createAssignment(
            $period,
            $teacherTwo,
            $this->actor
        );

        $this->withToken(JWTAuth::fromUser($this->actor));

        $this->patchJson(
            "/api/teacher-duty/periods/{$period->id}/end",
            ['reason' => 'Duty week complete']
        )->assertOk();

        foreach ([$assignmentOne, $assignmentTwo] as $assignment) {
            $this->assertDatabaseHas('teacher_duty_assignments', [
                'id' => $assignment->id,
                'active' => false,
                'ended_by' => $this->actor->id,
                'end_reason' => 'Duty week complete',
            ]);
        }
    }

    public function test_tenant_mismatch_fails_closed(): void
    {
        $otherSchool = $this->createSchool();
        $otherActor = $this->createUser($otherSchool);
        $period = $this->createPeriod($otherActor);

        $this->withToken(JWTAuth::fromUser($this->actor));

        $this->getJson(
            "/api/teacher-duty/periods/{$period->id}"
        )->assertUnprocessable();
    }

    public function test_current_and_history_routes_have_expected_shape(): void
    {
        $current = $this->createPeriod($this->actor);
        $ended = $this->createPeriod($this->actor);

        $ended->active = false;
        $ended->ended_by = $this->actor->id;
        $ended->ended_at = now();
        $ended->end_reason = 'Historical';
        $ended->save();

        $this->withToken(JWTAuth::fromUser($this->actor));

        $this->getJson('/api/teacher-duty/periods')
            ->assertOk()
            ->assertJsonPath('data.0.id', $current->id);

        $historyResponse = $this->getJson(
            '/api/teacher-duty/periods/history'
        );

        $historyResponse
            ->assertOk()
            ->assertJsonFragment([
                'id' => $ended->id,
                'active' => false,
            ]);
    }

    public function test_end_reason_boundary_is_enforced(): void
    {
        $period = $this->createPeriod($this->actor);

        $this->withToken(JWTAuth::fromUser($this->actor));

        $this->patchJson(
            "/api/teacher-duty/periods/{$period->id}/end",
            ['reason' => str_repeat('a', 501)]
        )->assertUnprocessable();

        $this->patchJson(
            "/api/teacher-duty/periods/{$period->id}/end",
            ['reason' => str_repeat('a', 500)]
        )->assertOk();
    }

    public function test_unauthenticated_user_cannot_use_teacher_duty_endpoints(): void
    {
        $periodId = (string) Str::uuid();
        $assignmentId = (string) Str::uuid();
        $teacherId = (string) Str::uuid();

        $requests = [
            ['GET', '/api/teacher-duty/periods', []],
            ['POST', '/api/teacher-duty/periods', [
                'start_date' => '2026-09-07',
                'end_date' => '2026-09-11',
            ]],
            ['GET', '/api/teacher-duty/periods/history', []],
            ['GET', "/api/teacher-duty/periods/{$periodId}", []],
            ['PATCH', "/api/teacher-duty/periods/{$periodId}/end", [
                'reason' => 'Unauthenticated attempt',
            ]],
            ['GET', "/api/teacher-duty/periods/{$periodId}/assignments", []],
            ['POST', "/api/teacher-duty/periods/{$periodId}/assignments", [
                'teacher_id' => $teacherId,
            ]],
            ['GET', "/api/teacher-duty/periods/{$periodId}/assignments/history", []],
            ['GET', "/api/teacher-duty/assignments/{$assignmentId}", []],
            ['PATCH', "/api/teacher-duty/assignments/{$assignmentId}/end", [
                'reason' => 'Unauthenticated attempt',
            ]],
        ];

        foreach ($requests as [$method, $uri, $payload]) {
            $this->json($method, $uri, $payload)
                ->assertUnauthorized();
        }
    }

    public function test_teacher_duty_assignment_does_not_grant_roster_authorization(): void
    {
        $period = $this->createPeriod($this->actor);
        $teacher = $this->createTeacher($this->school);

        $this->createAssignment(
            $period,
            $teacher,
            $this->actor
        );

        $teacherUser = User::query()
            ->whereKey($teacher->user_id)
            ->firstOrFail();

        $this->withToken(JWTAuth::fromUser($teacherUser))
            ->getJson('/api/teacher-duty/periods')
            ->assertForbidden();

        $this->withToken(JWTAuth::fromUser($teacherUser))
            ->postJson(
                "/api/teacher-duty/periods/{$period->id}/assignments",
                [
                    'teacher_id' => $teacher->id,
                ]
            )
            ->assertForbidden();
    }

    public function test_create_period_and_assignment_are_blocked_for_non_operational_school(): void
    {
        $school = $this->createSchool();
        $actor = $this->createUser($school);

        $this->grantPermission(
            $actor,
            'manage_teacher_duty_roster'
        );

        $this->withToken(JWTAuth::fromUser($actor));

        $periodCreate = $this->postJson(
            '/api/teacher-duty/periods',
            [
                'start_date' => '2026-09-14',
                'end_date' => '2026-09-18',
            ]
        );

        $this->assertFalse(
            $periodCreate->isSuccessful(),
            'Period creation must be blocked for a non-operational school.'
        );

        $period = $this->createPeriod($actor);
        $teacher = $this->createTeacher($school);

        $this->withToken(JWTAuth::fromUser($actor));

        $assignmentCreate = $this->postJson(
            "/api/teacher-duty/periods/{$period->id}/assignments",
            [
                'teacher_id' => $teacher->id,
            ]
        );

        $this->assertFalse(
            $assignmentCreate->isSuccessful(),
            'Teacher assignment must be blocked for a non-operational school.'
        );
    }

    public function test_duplicate_current_teacher_assignment_is_422_without_raw_sql_leakage(): void
    {
        $period = $this->createPeriod($this->actor);
        $teacher = $this->createTeacher($this->school);

        $payload = [
            'teacher_id' => $teacher->id,
        ];

        $this->withToken(JWTAuth::fromUser($this->actor));

        $this->postJson(
            "/api/teacher-duty/periods/{$period->id}/assignments",
            $payload
        )->assertCreated();

        $this->withToken(JWTAuth::fromUser($this->actor));

        $response = $this->postJson(
            "/api/teacher-duty/periods/{$period->id}/assignments",
            $payload
        );

        $response->assertUnprocessable();

        $body = Str::lower($response->getContent());

        $this->assertStringNotContainsString('sqlstate', $body);
        $this->assertStringNotContainsString('duplicate key', $body);
        $this->assertStringNotContainsString(
            'teacher_duty_assignments',
            $body
        );
    }

    public function test_foreign_teacher_identifier_fails_like_missing_teacher_identifier(): void
    {
        $period = $this->createPeriod($this->actor);

        $otherSchool = $this->createSchool();
        $foreignTeacher = $this->createTeacher($otherSchool);

        $this->withToken(JWTAuth::fromUser($this->actor));

        $foreignResponse = $this->postJson(
            "/api/teacher-duty/periods/{$period->id}/assignments",
            [
                'teacher_id' => $foreignTeacher->id,
            ]
        );

        $this->withToken(JWTAuth::fromUser($this->actor));

        $missingResponse = $this->postJson(
            "/api/teacher-duty/periods/{$period->id}/assignments",
            [
                'teacher_id' => (string) Str::uuid(),
            ]
        );

        $foreignResponse->assertUnprocessable();
        $missingResponse->assertUnprocessable();

        $this->assertSame(
            $missingResponse->json('errors.teacher_id'),
            $foreignResponse->json('errors.teacher_id')
        );

        $this->assertDatabaseMissing('teacher_duty_assignments', [
            'school_id' => $this->school->id,
            'duty_period_id' => $period->id,
            'teacher_id' => $foreignTeacher->id,
        ]);
    }

    public function test_cross_tenant_period_and_assignment_reads_and_end_fail_closed(): void
    {
        $otherSchool = $this->createSchool();
        $otherActor = $this->createUser($otherSchool);
        $otherTeacher = $this->createTeacher($otherSchool);

        $period = $this->createPeriod($otherActor);

        $assignment = $this->createAssignment(
            $period,
            $otherTeacher,
            $otherActor
        );

        $requests = [
            ['GET', "/api/teacher-duty/periods/{$period->id}", []],
            ['GET', "/api/teacher-duty/periods/{$period->id}/assignments", []],
            ['GET', "/api/teacher-duty/periods/{$period->id}/assignments/history", []],
            ['PATCH', "/api/teacher-duty/periods/{$period->id}/end", [
                'reason' => 'Foreign tenant attempt',
            ]],
            ['GET', "/api/teacher-duty/assignments/{$assignment->id}", []],
            ['PATCH', "/api/teacher-duty/assignments/{$assignment->id}/end", [
                'reason' => 'Foreign tenant attempt',
            ]],
        ];

        foreach ($requests as [$method, $uri, $payload]) {
            $this->withToken(JWTAuth::fromUser($this->actor));

            $this->json($method, $uri, $payload)
                ->assertUnprocessable();
        }

        $this->assertDatabaseHas('teacher_duty_periods', [
            'id' => $period->id,
            'school_id' => $otherSchool->id,
            'active' => true,
            'ended_by' => null,
        ]);

        $this->assertDatabaseHas('teacher_duty_assignments', [
            'id' => $assignment->id,
            'school_id' => $otherSchool->id,
            'active' => true,
            'ended_by' => null,
        ]);
    }

    public function test_historical_assignment_reads_survive_teacher_and_user_retirement(): void
    {
        $period = $this->createPeriod($this->actor);
        $teacher = $this->createTeacher($this->school);

        $assignment = $this->createAssignment(
            $period,
            $teacher,
            $this->actor
        );

        app(TeacherDutyRosterService::class)->endAssignment(
            (string) $this->school->id,
            (string) $assignment->id,
            (string) $this->actor->id,
            'Historical assignment'
        );

        DB::table('teachers')
            ->where('id', $teacher->id)
            ->update([
                'active' => false,
                'is_deleted' => true,
            ]);

        DB::table('users')
            ->where('id', $teacher->user_id)
            ->update([
                'active' => false,
                'is_deleted' => true,
            ]);

        $this->withToken(JWTAuth::fromUser($this->actor));

        $this->getJson(
            "/api/teacher-duty/periods/{$period->id}/assignments/history"
        )
            ->assertOk()
            ->assertJsonFragment([
                'id' => $assignment->id,
                'active' => false,
            ]);

        $this->withToken(JWTAuth::fromUser($this->actor));

        $this->getJson(
            "/api/teacher-duty/assignments/{$assignment->id}"
        )
            ->assertOk()
            ->assertJsonPath('data.id', $assignment->id)
            ->assertJsonPath('data.active', false);
    }

    public function test_terminal_period_and_assignment_transitions_return_422_when_repeated(): void
    {
        $period = $this->createPeriod($this->actor);
        $teacher = $this->createTeacher($this->school);

        $assignment = $this->createAssignment(
            $period,
            $teacher,
            $this->actor
        );

        $this->withToken(JWTAuth::fromUser($this->actor));

        $this->patchJson(
            "/api/teacher-duty/assignments/{$assignment->id}/end",
            [
                'reason' => 'First assignment end',
            ]
        )->assertOk();

        $this->withToken(JWTAuth::fromUser($this->actor));

        $this->patchJson(
            "/api/teacher-duty/assignments/{$assignment->id}/end",
            [
                'reason' => 'Second assignment end',
            ]
        )->assertUnprocessable();

        $this->withToken(JWTAuth::fromUser($this->actor));

        $this->patchJson(
            "/api/teacher-duty/periods/{$period->id}/end",
            [
                'reason' => 'First period end',
            ]
        )->assertOk();

        $this->withToken(JWTAuth::fromUser($this->actor));

        $this->patchJson(
            "/api/teacher-duty/periods/{$period->id}/end",
            [
                'reason' => 'Second period end',
            ]
        )->assertUnprocessable();
    }

    public function test_end_requests_reject_client_control_of_server_owned_fields(): void
    {
        $period = $this->createPeriod($this->actor);
        $teacher = $this->createTeacher($this->school);

        $assignment = $this->createAssignment(
            $period,
            $teacher,
            $this->actor
        );

        $this->withToken(JWTAuth::fromUser($this->actor));

        $this->patchJson(
            "/api/teacher-duty/periods/{$period->id}/end",
            [
                'active' => false,
                'ended_by' => (string) Str::uuid(),
                'ended_at' => now()->toISOString(),
                'end_reason' => 'Client-owned lifecycle state',
            ]
        )->assertUnprocessable();

        $this->withToken(JWTAuth::fromUser($this->actor));

        $this->patchJson(
            "/api/teacher-duty/assignments/{$assignment->id}/end",
            [
                'active' => false,
                'ended_by' => (string) Str::uuid(),
                'ended_at' => now()->toISOString(),
                'end_reason' => 'Client-owned lifecycle state',
            ]
        )->assertUnprocessable();

        $this->assertDatabaseHas('teacher_duty_periods', [
            'id' => $period->id,
            'active' => true,
            'ended_by' => null,
        ]);

        $this->assertDatabaseHas('teacher_duty_assignments', [
            'id' => $assignment->id,
            'active' => true,
            'ended_by' => null,
        ]);
    }

    public function test_successful_mutations_create_authoritative_audit_records(): void
    {
        $teacher = $this->createTeacher($this->school);

        $this->withToken(JWTAuth::fromUser($this->actor));

        $periodResponse = $this->postJson(
            '/api/teacher-duty/periods',
            [
                'start_date' => '2026-09-21',
                'end_date' => '2026-09-25',
            ]
        )->assertCreated();

        $periodId = (string) $periodResponse->json('data.id');

        $this->assertDatabaseHas('audit_logs', [
            'school_id' => $this->school->id,
            'user_id' => $this->actor->id,
            'module' => 'Teacher Duty Roster',
            'action' => 'Create',
            'table_name' => 'teacher_duty_periods',
            'record_id' => $periodId,
        ]);

        $this->withToken(JWTAuth::fromUser($this->actor));

        $assignmentResponse = $this->postJson(
            "/api/teacher-duty/periods/{$periodId}/assignments",
            [
                'teacher_id' => $teacher->id,
            ]
        )->assertCreated();

        $assignmentId = (string) $assignmentResponse->json('data.id');

        $this->assertDatabaseHas('audit_logs', [
            'school_id' => $this->school->id,
            'user_id' => $this->actor->id,
            'module' => 'Teacher Duty Roster',
            'action' => 'Create',
            'table_name' => 'teacher_duty_assignments',
            'record_id' => $assignmentId,
        ]);

        $this->withToken(JWTAuth::fromUser($this->actor));

        $this->patchJson(
            "/api/teacher-duty/assignments/{$assignmentId}/end",
            [
                'reason' => 'Audit assignment end',
            ]
        )->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'school_id' => $this->school->id,
            'user_id' => $this->actor->id,
            'module' => 'Teacher Duty Roster',
            'action' => 'End',
            'table_name' => 'teacher_duty_assignments',
            'record_id' => $assignmentId,
        ]);

        $this->withToken(JWTAuth::fromUser($this->actor));

        $this->patchJson(
            "/api/teacher-duty/periods/{$periodId}/end",
            [
                'reason' => 'Audit period end',
            ]
        )->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'school_id' => $this->school->id,
            'user_id' => $this->actor->id,
            'module' => 'Teacher Duty Roster',
            'action' => 'End',
            'table_name' => 'teacher_duty_periods',
            'record_id' => $periodId,
        ]);

        $audits = DB::table('audit_logs')
            ->where('school_id', $this->school->id)
            ->where('user_id', $this->actor->id)
            ->where('module', 'Teacher Duty Roster')
            ->whereIn('record_id', [
                $periodId,
                $assignmentId,
            ])
            ->get();

        $this->assertCount(4, $audits);

        foreach ($audits as $audit) {
            $newValues = json_decode(
                (string) $audit->new_values,
                true
            );

            $this->assertIsArray($newValues);

            foreach ([
                'id',
                'school_id',
                'created_by',
                'assigned_by',
                'ended_by',
            ] as $authorityField) {
                $this->assertArrayNotHasKey(
                    $authorityField,
                    $newValues
                );
            }
        }
    }

    public function test_teacher_duty_routes_do_not_reuse_other_permissions_or_entitlement_gates(): void
    {
        $routes = collect(Route::getRoutes()->getRoutes())
            ->filter(
                fn ($route): bool => str_starts_with(
                    $route->uri(),
                    'api/teacher-duty'
                )
            )
            ->values();

        $this->assertCount(10, $routes);

        foreach ($routes as $route) {
            $middleware = $route->gatherMiddleware();

            $this->assertNotContains(
                'permission:manage_boarding',
                $middleware
            );

            $this->assertNotContains(
                'permission:manage_timetable',
                $middleware
            );

            $this->assertNotContains(
                'permission:manage_academics',
                $middleware
            );

            $middlewareText = Str::lower(
                implode('|', $middleware)
            );

            $this->assertStringNotContainsString(
                'entitlement',
                $middlewareText
            );

            $this->assertStringNotContainsString(
                'subscription',
                $middlewareText
            );
        }
    }

    private function createSchool(): School
    {
        return School::query()->create([
            'id' => (string) Str::uuid(),
            'school_name' => 'Teacher Duty HTTP '.Str::upper(
                Str::random(8)
            ),
            'school_code' => 'TDH-'.Str::upper(
                Str::random(8)
            ),
            'short_name' => 'TDH',
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
            'role_name' => 'Teacher Duty HTTP '.Str::upper(
                Str::random(8)
            ),
            'description' => 'Teacher duty HTTP test role',
            'active' => true,
        ]);

        return User::query()->create([
            'id' => (string) Str::uuid(),
            'school_id' => $school->id,
            'role_id' => $role->id,
            'first_name' => 'Teacher',
            'last_name' => 'Duty HTTP',
            'username' => 'teacher_duty_http_'.Str::lower(
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

    private function createTeacher(School $school): Teacher
    {
        $user = $this->createUser($school);

        return Teacher::query()->create([
            'id' => (string) Str::uuid(),
            'school_id' => $school->id,
            'user_id' => $user->id,
            'active' => true,
            'is_deleted' => false,
        ]);
    }

    private function createPeriod(User $actor): TeacherDutyPeriod
    {
        return app(TeacherDutyRosterService::class)
            ->createPeriod(
                (string) $actor->school_id,
                '2026-09-07',
                '2026-09-11',
                null,
                (string) $actor->id
            );
    }

    private function createAssignment(
        TeacherDutyPeriod $period,
        Teacher $teacher,
        User $actor
    ): TeacherDutyAssignment {
        return app(TeacherDutyRosterService::class)
            ->assignTeacher(
                (string) $actor->school_id,
                (string) $period->id,
                (string) $teacher->id,
                (string) $actor->id
            );
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
                'Teacher Duty HTTP test actor has no primary role.'
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
