<?php

declare(strict_types=1);

namespace Tests\Feature\Boarding;

use App\Models\Grade;
use App\Models\Hostel;
use App\Models\HostelBed;
use App\Models\HostelRoom;
use App\Models\Learner;
use App\Models\Role;
use App\Models\School;
use App\Models\Stream;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Tests\TestCase;

class BoardingStructureManagementTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'jwt.secret' => str_repeat('a', 64),
        ]);
    }

    public function test_boarding_module_permission_policy_is_registered(): void
    {
        $this->assertSame(
            'manage_boarding',
            config('module_permissions.boarding')
        );
    }

    public function test_manage_boarding_permission_is_provisioned_for_authorized_system_roles(): void
    {
        $permissionId = DB::table('permissions')
            ->where('permission_name', 'manage_boarding')
            ->value('id');

        $this->assertNotNull($permissionId);

        foreach ([
            'School Admin',
            'Administrator',
        ] as $roleName) {
            $roleId = DB::table('roles')
                ->where('role_name', $roleName)
                ->whereNull('school_id')
                ->where('system_role', true)
                ->where('active', true)
                ->value('id');

            $this->assertNotNull(
                $roleId,
                "{$roleName} system role must exist."
            );

            $this->assertTrue(
                DB::table('role_permissions')
                    ->where('role_id', $roleId)
                    ->where('permission_id', $permissionId)
                    ->exists(),
                "{$roleName} must receive manage_boarding."
            );
        }
    }

    public function test_boarding_permission_is_not_granted_to_unrelated_system_roles(): void
    {
        $permissionId = DB::table('permissions')
            ->where('permission_name', 'manage_boarding')
            ->value('id');

        $this->assertNotNull($permissionId);

        $roleIds = DB::table('roles')
            ->whereIn('role_name', [
                'Platform Owner',
                'Platform Super Administrator',
                'Principal',
                'Teacher',
                'Learner',
                'Parent',
            ])
            ->whereNull('school_id')
            ->where('system_role', true)
            ->pluck('id');

        $this->assertFalse(
            DB::table('role_permissions')
                ->whereIn('role_id', $roleIds)
                ->where('permission_id', $permissionId)
                ->exists()
        );
    }

    public function test_boarding_routes_have_expected_authorization_and_operational_gates(): void
    {
        $id = '00000000-0000-0000-0000-000000000001';

        $routes = [
            ['GET', '/api/boarding/hostels', false],
            ['POST', '/api/boarding/hostels', true],
            ['GET', "/api/boarding/hostels/{$id}", false],
            ['PATCH', "/api/boarding/hostels/{$id}", true],
            ['DELETE', "/api/boarding/hostels/{$id}", true],

            ['GET', "/api/boarding/hostels/{$id}/rooms", false],
            ['POST', "/api/boarding/hostels/{$id}/rooms", true],
            ['GET', "/api/boarding/rooms/{$id}", false],
            ['PATCH', "/api/boarding/rooms/{$id}", true],
            ['DELETE', "/api/boarding/rooms/{$id}", true],

            ['GET', "/api/boarding/rooms/{$id}/beds", false],
            ['POST', "/api/boarding/rooms/{$id}/beds", true],
            ['GET', "/api/boarding/beds/{$id}", false],
            ['PATCH', "/api/boarding/beds/{$id}", true],
            ['DELETE', "/api/boarding/beds/{$id}", true],
        ];

        foreach ($routes as [$method, $uri, $mutation]) {
            $route = Route::getRoutes()->match(
                Request::create($uri, $method)
            );

            $middleware = $route->gatherMiddleware();

            $this->assertContains(
                'permission:manage_boarding',
                $middleware,
                "{$method} {$uri} must require manage_boarding."
            );

            if ($mutation) {
                $this->assertContains(
                    'school.operational',
                    $middleware,
                    "{$method} {$uri} must require school.operational."
                );
            } else {
                $this->assertNotContains(
                    'school.operational',
                    $middleware,
                    "{$method} {$uri} must remain readable while non-operational."
                );
            }
        }
    }

    public function test_unauthenticated_boarding_access_is_denied(): void
    {
        $this->getJson('/api/boarding/hostels')
            ->assertUnauthorized();
    }

    public function test_user_without_manage_boarding_is_denied(): void
    {
        $school = $this->school();
        $user = $this->user($school);

        $this->completeOperationalSetup($school);

        $this->withToken($this->tokenFor($user))
            ->getJson('/api/boarding/hostels')
            ->assertForbidden();
    }

    public function test_non_operational_school_can_read_but_cannot_mutate_boarding(): void
    {
        [$school, $user] = $this->authorizedSchoolUser(
            operational: false
        );

        $this->withToken($this->tokenFor($user))
            ->getJson('/api/boarding/hostels')
            ->assertOk();

        $this->withToken($this->tokenFor($user))
            ->postJson('/api/boarding/hostels', [
                'hostel_name' => 'Boys House',
                'hostel_type' => 'BOYS',
                'capacity' => 40,
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('hostels', [
            'school_id' => $school->id,
            'hostel_name' => 'Boys House',
        ]);
    }

    public function test_authorized_school_can_create_hostel_and_audit_is_written(): void
    {
        [$school, $user] = $this->authorizedSchoolUser();

        $response = $this->withToken($this->tokenFor($user))
            ->postJson('/api/boarding/hostels', [
                'hostel_name' => 'Senior Boys',
                'hostel_type' => 'BOYS',
                'capacity' => 60,
            ])
            ->assertCreated()
            ->assertJsonPath(
                'data.hostel_name',
                'Senior Boys'
            )
            ->assertJsonPath(
                'data.hostel_type',
                'BOYS'
            );

        $hostelId = (string) $response->json('data.id');

        $this->assertDatabaseHas('hostels', [
            'id' => $hostelId,
            'school_id' => $school->id,
            'hostel_name' => 'Senior Boys',
            'hostel_type' => 'BOYS',
            'active' => true,
            'is_deleted' => false,
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'school_id' => $school->id,
            'user_id' => $user->id,
            'module' => 'Boarding',
            'action' => 'Create',
            'table_name' => 'hostels',
            'record_id' => $hostelId,
        ]);
    }

    public function test_client_cannot_redirect_hostel_to_foreign_school_and_server_fields_remain_protected(): void
    {
        [$school, $user] = $this->authorizedSchoolUser();
        $foreignSchool = $this->school();

        $response = $this->withToken($this->tokenFor($user))
            ->postJson('/api/boarding/hostels', [
                'hostel_name' => 'Tenant Safe Hostel',
                'hostel_type' => 'BOYS',
                'capacity' => 20,
                'school_id' => $foreignSchool->id,
            ])
            ->assertCreated();

        $hostelId = (string) $response->json('data.id');

        $this->assertDatabaseHas('hostels', [
            'id' => $hostelId,
            'school_id' => $school->id,
            'hostel_name' => 'Tenant Safe Hostel',
        ]);

        $this->assertDatabaseMissing('hostels', [
            'id' => $hostelId,
            'school_id' => $foreignSchool->id,
        ]);

        $this->withToken($this->tokenFor($user))
            ->postJson('/api/boarding/hostels', [
                'hostel_name' => 'Server Field Tamper',
                'hostel_type' => 'BOYS',
                'capacity' => 20,
                'active' => false,
                'is_deleted' => true,
                'deleted_at' => now()->toISOString(),
                'deleted_by' => (string) Str::uuid(),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'active',
                'is_deleted',
                'deleted_at',
                'deleted_by',
            ]);
    }

    public function test_mixed_hostel_is_rejected(): void
    {
        [, $user] = $this->authorizedSchoolUser();

        $this->withToken($this->tokenFor($user))
            ->postJson('/api/boarding/hostels', [
                'hostel_name' => 'Mixed House',
                'hostel_type' => 'MIXED',
                'capacity' => 30,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'hostel_type',
            ]);
    }

    public function test_hostel_listing_does_not_leak_another_school(): void
    {
        [$school, $user] = $this->authorizedSchoolUser();
        $foreignSchool = $this->school();

        $own = $this->hostel(
            $school,
            'School A Boys',
            'BOYS',
            20
        );

        $foreign = $this->hostel(
            $foreignSchool,
            'School B Girls',
            'GIRLS',
            20
        );

        $response = $this->withToken($this->tokenFor($user))
            ->getJson('/api/boarding/hostels')
            ->assertOk();

        $ids = collect($response->json('data'))
            ->pluck('id')
            ->all();

        $this->assertContains($own->id, $ids);
        $this->assertNotContains($foreign->id, $ids);
    }

    public function test_foreign_hostel_identifier_fails_closed(): void
    {
        [, $user] = $this->authorizedSchoolUser();

        $foreignSchool = $this->school();
        $foreignHostel = $this->hostel(
            $foreignSchool,
            'Foreign Hostel',
            'BOYS',
            20
        );

        $this->withToken($this->tokenFor($user))
            ->getJson(
                '/api/boarding/hostels/'.$foreignHostel->id
            )
            ->assertNotFound();
    }

    public function test_room_parent_is_server_controlled_and_foreign_hostel_fails_closed(): void
    {
        [$school, $user] = $this->authorizedSchoolUser();

        $ownHostel = $this->hostel(
            $school,
            'Own Hostel',
            'BOYS',
            20
        );

        $foreignSchool = $this->school();

        $foreignHostel = $this->hostel(
            $foreignSchool,
            'Foreign Hostel',
            'BOYS',
            20
        );

        $this->withToken($this->tokenFor($user))
            ->postJson(
                '/api/boarding/hostels/'.$ownHostel->id.'/rooms',
                [
                    'room_name' => 'Room A',
                    'capacity' => 4,
                    'hostel_id' => $foreignHostel->id,
                ]
            )
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'hostel_id',
            ]);

        $this->withToken($this->tokenFor($user))
            ->postJson(
                '/api/boarding/hostels/'.$foreignHostel->id.'/rooms',
                [
                    'room_name' => 'Room B',
                    'capacity' => 4,
                ]
            )
            ->assertNotFound();
    }

    public function test_bed_parent_is_server_controlled_and_foreign_room_fails_closed(): void
    {
        [$school, $user] = $this->authorizedSchoolUser();

        $hostel = $this->hostel(
            $school,
            'Own Hostel',
            'BOYS',
            20
        );

        $room = $this->room(
            $school,
            $hostel,
            'Own Room',
            4
        );

        $foreignSchool = $this->school();

        $foreignHostel = $this->hostel(
            $foreignSchool,
            'Foreign Hostel',
            'BOYS',
            20
        );

        $foreignRoom = $this->room(
            $foreignSchool,
            $foreignHostel,
            'Foreign Room',
            4
        );

        $this->withToken($this->tokenFor($user))
            ->postJson(
                '/api/boarding/rooms/'.$room->id.'/beds',
                [
                    'bed_number' => 'B-01',
                    'room_id' => $foreignRoom->id,
                ]
            )
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'room_id',
            ]);

        $this->withToken($this->tokenFor($user))
            ->postJson(
                '/api/boarding/rooms/'.$foreignRoom->id.'/beds',
                [
                    'bed_number' => 'B-02',
                ]
            )
            ->assertNotFound();
    }

    public function test_room_bed_capacity_is_enforced(): void
    {
        [$school, $user] = $this->authorizedSchoolUser();

        $hostel = $this->hostel(
            $school,
            'Capacity Hostel',
            'BOYS',
            10
        );

        $room = $this->room(
            $school,
            $hostel,
            'Capacity Room',
            1
        );

        $this->withToken($this->tokenFor($user))
            ->postJson(
                '/api/boarding/rooms/'.$room->id.'/beds',
                [
                    'bed_number' => 'BED-1',
                ]
            )
            ->assertCreated();

        $this->withToken($this->tokenFor($user))
            ->postJson(
                '/api/boarding/rooms/'.$room->id.'/beds',
                [
                    'bed_number' => 'BED-2',
                ]
            )
            ->assertUnprocessable();

        $this->assertSame(
            1,
            HostelBed::query()
                ->withoutGlobalScopes()
                ->where('school_id', $school->id)
                ->where('room_id', $room->id)
                ->where('is_deleted', false)
                ->count()
        );
    }

    public function test_hostel_bed_capacity_is_enforced_across_rooms(): void
    {
        [$school, $user] = $this->authorizedSchoolUser();

        $hostel = $this->hostel(
            $school,
            'Small Hostel',
            'BOYS',
            1
        );

        $roomA = $this->room(
            $school,
            $hostel,
            'Room A',
            1
        );

        $roomB = $this->room(
            $school,
            $hostel,
            'Room B',
            1
        );

        $this->withToken($this->tokenFor($user))
            ->postJson(
                '/api/boarding/rooms/'.$roomA->id.'/beds',
                [
                    'bed_number' => 'A-1',
                ]
            )
            ->assertCreated();

        $this->withToken($this->tokenFor($user))
            ->postJson(
                '/api/boarding/rooms/'.$roomB->id.'/beds',
                [
                    'bed_number' => 'B-1',
                ]
            )
            ->assertUnprocessable();
    }

    public function test_room_capacity_cannot_be_reduced_below_active_bed_count(): void
    {
        [$school, $user] = $this->authorizedSchoolUser();

        $hostel = $this->hostel(
            $school,
            'Room Capacity Hostel',
            'BOYS',
            10
        );

        $room = $this->room(
            $school,
            $hostel,
            'Room Capacity',
            3
        );

        $this->bed(
            $school,
            $room,
            'BED-1'
        );

        $this->bed(
            $school,
            $room,
            'BED-2'
        );

        $this->withToken($this->tokenFor($user))
            ->patchJson(
                '/api/boarding/rooms/'.$room->id,
                [
                    'capacity' => 1,
                ]
            )
            ->assertUnprocessable();

        $this->assertSame(
            3,
            HostelRoom::query()
                ->withoutGlobalScopes()
                ->findOrFail($room->id)
                ->capacity
        );
    }

    public function test_hostel_capacity_cannot_be_reduced_below_active_bed_count(): void
    {
        [$school, $user] = $this->authorizedSchoolUser();

        $hostel = $this->hostel(
            $school,
            'Hostel Capacity',
            'BOYS',
            5
        );

        $room = $this->room(
            $school,
            $hostel,
            'Room A',
            5
        );

        $this->bed(
            $school,
            $room,
            'BED-1'
        );

        $this->bed(
            $school,
            $room,
            'BED-2'
        );

        $this->withToken($this->tokenFor($user))
            ->patchJson(
                '/api/boarding/hostels/'.$hostel->id,
                [
                    'capacity' => 1,
                ]
            )
            ->assertUnprocessable();

        $this->assertSame(
            5,
            Hostel::query()
                ->withoutGlobalScopes()
                ->findOrFail($hostel->id)
                ->capacity
        );
    }

    public function test_room_with_current_bed_cannot_be_archived(): void
    {
        [$school, $user] = $this->authorizedSchoolUser();

        $hostel = $this->hostel(
            $school,
            'Archive Hostel',
            'BOYS',
            10
        );

        $room = $this->room(
            $school,
            $hostel,
            'Archive Room',
            2
        );

        $this->bed(
            $school,
            $room,
            'BED-1'
        );

        $this->withToken($this->tokenFor($user))
            ->deleteJson(
                '/api/boarding/rooms/'.$room->id
            )
            ->assertUnprocessable();

        $this->assertDatabaseHas('hostel_rooms', [
            'id' => $room->id,
            'is_deleted' => false,
        ]);
    }

    public function test_hostel_with_current_room_cannot_be_archived(): void
    {
        [$school, $user] = $this->authorizedSchoolUser();

        $hostel = $this->hostel(
            $school,
            'Archive Parent Hostel',
            'BOYS',
            10
        );

        $this->room(
            $school,
            $hostel,
            'Room A',
            2
        );

        $this->withToken($this->tokenFor($user))
            ->deleteJson(
                '/api/boarding/hostels/'.$hostel->id
            )
            ->assertUnprocessable();

        $this->assertDatabaseHas('hostels', [
            'id' => $hostel->id,
            'is_deleted' => false,
        ]);
    }

    public function test_bed_with_active_allocation_cannot_be_archived(): void
    {
        [$school, $user] = $this->authorizedSchoolUser();

        $hostel = $this->hostel(
            $school,
            'Occupied Hostel',
            'GIRLS',
            10
        );

        $room = $this->room(
            $school,
            $hostel,
            'Occupied Room',
            2
        );

        $bed = $this->bed(
            $school,
            $room,
            'BED-1'
        );

        $learner = $this->learner($school);

        DB::table('bed_allocations')->insert([
            'id' => (string) Str::uuid(),
            'school_id' => $school->id,
            'learner_id' => $learner->id,
            'bed_id' => $bed->id,
            'allocation_date' => now()->toDateString(),
            'release_date' => null,
            'active' => true,
            'allocated_by' => $user->id,
            'created_at' => now(),
        ]);

        $this->withToken($this->tokenFor($user))
            ->deleteJson(
                '/api/boarding/beds/'.$bed->id
            )
            ->assertUnprocessable();

        $this->assertDatabaseHas('hostel_beds', [
            'id' => $bed->id,
            'is_deleted' => false,
            'active' => true,
        ]);
    }

    public function test_structure_can_be_archived_in_safe_child_to_parent_order(): void
    {
        [$school, $user] = $this->authorizedSchoolUser();

        $hostel = $this->hostel(
            $school,
            'Retirement Hostel',
            'BOYS',
            10
        );

        $room = $this->room(
            $school,
            $hostel,
            'Retirement Room',
            2
        );

        $bed = $this->bed(
            $school,
            $room,
            'BED-1'
        );

        $this->withToken($this->tokenFor($user))
            ->deleteJson(
                '/api/boarding/beds/'.$bed->id
            )
            ->assertOk();

        $this->withToken($this->tokenFor($user))
            ->deleteJson(
                '/api/boarding/rooms/'.$room->id
            )
            ->assertOk();

        $this->withToken($this->tokenFor($user))
            ->deleteJson(
                '/api/boarding/hostels/'.$hostel->id
            )
            ->assertOk();

        $this->assertDatabaseHas('hostel_beds', [
            'id' => $bed->id,
            'active' => false,
            'is_deleted' => true,
            'deleted_by' => $user->id,
        ]);

        $this->assertDatabaseHas('hostel_rooms', [
            'id' => $room->id,
            'active' => false,
            'is_deleted' => true,
            'deleted_by' => $user->id,
        ]);

        $this->assertDatabaseHas('hostels', [
            'id' => $hostel->id,
            'active' => false,
            'is_deleted' => true,
            'deleted_by' => $user->id,
        ]);

        $this->assertSame(
            3,
            DB::table('audit_logs')
                ->where('school_id', $school->id)
                ->where('module', 'Boarding')
                ->where('action', 'Archive')
                ->count()
        );
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

        return [
            $school,
            $user,
        ];
    }

    private function grantManageBoarding(
        User $user
    ): void {
        $permissionId = DB::table('permissions')
            ->where('permission_name', 'manage_boarding')
            ->value('id');

        $this->assertNotNull(
            $permissionId,
            'manage_boarding must be provisioned by the production migration.'
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
            'school_name' => 'School '.Str::upper(Str::random(8)),
            'school_code' => 'BRD-'.Str::upper(Str::random(8)),
            'short_name' => 'BRD',
            'registration_number' => 'REG-'.Str::upper(Str::random(10)),
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

    private function user(
        School $school
    ): User {
        $role = Role::query()->create([
            'id' => (string) Str::uuid(),
            'role_name' => 'Boarding Test '.Str::upper(
                Str::random(8)
            ),
            'description' => 'Boarding feature test role',
            'active' => true,
        ]);

        return User::query()->create([
            'id' => (string) Str::uuid(),
            'school_id' => $school->id,
            'role_id' => $role->id,
            'first_name' => 'Boarding',
            'last_name' => 'Admin',
            'username' => 'boarding_'.Str::lower(
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
            'end_date' => '2026-04-30',
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

    private function hostel(
        School $school,
        string $name,
        string $type,
        ?int $capacity
    ): Hostel {
        $hostel = new Hostel;

        $hostel->school_id = $school->id;
        $hostel->hostel_name = $name;
        $hostel->hostel_type = $type;
        $hostel->capacity = $capacity;
        $hostel->active = true;
        $hostel->is_deleted = false;

        $hostel->save();

        return $hostel->refresh();
    }

    private function room(
        School $school,
        Hostel $hostel,
        string $name,
        ?int $capacity
    ): HostelRoom {
        $room = new HostelRoom;

        $room->school_id = $school->id;
        $room->hostel_id = $hostel->id;
        $room->room_name = $name;
        $room->floor_number = null;
        $room->capacity = $capacity;
        $room->active = true;
        $room->is_deleted = false;

        $room->save();

        return $room->refresh();
    }

    private function bed(
        School $school,
        HostelRoom $room,
        string $number
    ): HostelBed {
        $bed = new HostelBed;

        $bed->school_id = $school->id;
        $bed->room_id = $room->id;
        $bed->bed_number = $number;
        $bed->active = true;
        $bed->is_deleted = false;

        $bed->save();

        return $bed->refresh();
    }

    private function learner(
        School $school
    ): Learner {
        $grade = Grade::query()
            ->withoutGlobalScopes()
            ->create([
                'id' => (string) Str::uuid(),
                'school_id' => $school->id,
                'grade_name' => 'Grade '.Str::upper(
                    Str::random(6)
                ),
                'grade_order' => random_int(
                    1,
                    1000
                ),
                'active' => true,
            ]);

        $stream = Stream::query()
            ->withoutGlobalScopes()
            ->create([
                'id' => (string) Str::uuid(),
                'school_id' => $school->id,
                'grade_id' => $grade->id,
                'stream_name' => 'Stream '.Str::upper(
                    Str::random(6)
                ),
                'active' => true,
                'created_at' => now(),
            ]);

        return Learner::query()
            ->withoutGlobalScopes()
            ->create([
                'id' => (string) Str::uuid(),
                'school_id' => $school->id,
                'admission_no' => 'BRD-'.Str::upper(
                    Str::random(8)
                ),
                'first_name' => 'Boarding',
                'last_name' => 'Learner',
                'gender' => 'Female',
                'grade_id' => $grade->id,
                'stream_id' => $stream->id,
                'admission_date' => now()->toDateString(),
                'active' => true,
                'is_deleted' => false,
                'portal_enabled' => false,
                'lifecycle_status' => 'active',
            ]);
    }

    private function tokenFor(
        User $user
    ): string {
        return JWTAuth::fromUser(
            User::query()
                ->withoutGlobalScopes()
                ->findOrFail($user->id)
        );
    }
}
