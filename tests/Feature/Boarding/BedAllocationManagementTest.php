<?php

declare(strict_types=1);

namespace Tests\Feature\Boarding;

use App\Models\Hostel;
use App\Models\HostelBed;
use App\Models\HostelRoom;
use App\Models\Learner;
use App\Models\Role;
use App\Models\School;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Tests\TestCase;

class BedAllocationManagementTest extends TestCase
{
    use DatabaseTransactions;

    private const ENDPOINT = '/api/boarding/bed-allocations';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'jwt.secret' => str_repeat('a', 64),
        ]);
    }

    public function test_bed_allocation_route_has_expected_security_and_operational_gates(): void
    {
        $route = collect(Route::getRoutes()->getRoutes())
            ->first(
                fn ($route): bool => in_array(
                    'POST',
                    $route->methods(),
                    true
                )
                && $route->uri() === 'api/boarding/bed-allocations'
            );

        $this->assertNotNull($route);

        $middleware = $route->gatherMiddleware();

        $this->assertContains(
            'permission:manage_boarding',
            $middleware
        );

        $this->assertContains(
            'school.operational',
            $middleware
        );
    }

    public function test_unauthenticated_user_cannot_allocate_bed(): void
    {
        $this->postJson(self::ENDPOINT, [
            'learner_id' => (string) Str::uuid(),
            'bed_id' => (string) Str::uuid(),
        ])->assertUnauthorized();
    }

    public function test_user_without_manage_boarding_cannot_allocate_bed(): void
    {
        $school = $this->school();
        $user = $this->user($school);

        $this->completeOperationalSetup($school);

        $learner = $this->learner(
            $school,
            'Female',
            'boarder',
            'active',
            true
        );

        $bed = $this->boardingBed(
            $school,
            'GIRLS'
        );

        $this->withToken($this->tokenFor($user))
            ->postJson(self::ENDPOINT, [
                'learner_id' => $learner->id,
                'bed_id' => $bed->id,
            ])
            ->assertForbidden();

        $this->assertSame(
            0,
            DB::table('bed_allocations')
                ->where('school_id', $school->id)
                ->count()
        );
    }

    public function test_non_operational_school_cannot_allocate_bed(): void
    {
        [$school, $user] = $this->authorizedSchoolUser(
            operational: false
        );

        $learner = $this->learner(
            $school,
            'Female',
            'boarder',
            'active',
            true
        );

        $bed = $this->boardingBed(
            $school,
            'GIRLS'
        );

        $this->withToken($this->tokenFor($user))
            ->postJson(self::ENDPOINT, [
                'learner_id' => $learner->id,
                'bed_id' => $bed->id,
            ])
            ->assertForbidden();

        $this->assertSame(
            0,
            DB::table('bed_allocations')
                ->where('school_id', $school->id)
                ->count()
        );
    }

    public function test_authorized_active_boarder_can_be_allocated_and_audit_is_written(): void
    {
        [$school, $user] = $this->authorizedSchoolUser();

        $learner = $this->learner(
            $school,
            'Female',
            'boarder',
            'active',
            true
        );

        $bed = $this->boardingBed(
            $school,
            'GIRLS'
        );

        $response = $this->withToken(
            $this->tokenFor($user)
        )->postJson(self::ENDPOINT, [
            'learner_id' => $learner->id,
            'bed_id' => $bed->id,
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath(
                'message',
                'Bed allocated successfully.'
            )
            ->assertJsonPath(
                'data.learner_id',
                $learner->id
            )
            ->assertJsonPath(
                'data.bed_id',
                $bed->id
            )
            ->assertJsonPath(
                'data.active',
                true
            )
            ->assertJsonPath(
                'data.allocated_by',
                $user->id
            )
            ->assertJsonMissingPath(
                'data.school_id'
            );

        $allocationId = (string) $response->json(
            'data.id'
        );

        $this->assertDatabaseHas(
            'bed_allocations',
            [
                'id' => $allocationId,
                'school_id' => $school->id,
                'learner_id' => $learner->id,
                'bed_id' => $bed->id,
                'active' => true,
                'allocated_by' => $user->id,
            ]
        );

        $this->assertSame(
            1,
            DB::table('audit_logs')
                ->where('school_id', $school->id)
                ->where('module', 'Boarding')
                ->where('action', 'Create')
                ->count()
        );
    }

    public function test_client_school_id_cannot_redirect_allocation_to_foreign_tenant(): void
    {
        [$school, $user] = $this->authorizedSchoolUser();

        $foreignSchool = $this->school();

        $learner = $this->learner(
            $school,
            'Male',
            'boarder',
            'active',
            true
        );

        $bed = $this->boardingBed(
            $school,
            'BOYS'
        );

        $response = $this->withToken(
            $this->tokenFor($user)
        )->postJson(self::ENDPOINT, [
            'school_id' => $foreignSchool->id,
            'learner_id' => $learner->id,
            'bed_id' => $bed->id,
        ]);

        $response
            ->assertCreated()
            ->assertJsonMissingPath(
                'data.school_id'
            );

        $allocationId = (string) $response->json(
            'data.id'
        );

        $this->assertDatabaseHas(
            'bed_allocations',
            [
                'id' => $allocationId,
                'school_id' => $school->id,
            ]
        );

        $this->assertDatabaseMissing(
            'bed_allocations',
            [
                'id' => $allocationId,
                'school_id' => $foreignSchool->id,
            ]
        );
    }

    public function test_allocation_server_owned_fields_are_prohibited(): void
    {
        [$school, $user] = $this->authorizedSchoolUser();

        $learner = $this->learner(
            $school,
            'Female',
            'boarder',
            'active',
            true
        );

        $bed = $this->boardingBed(
            $school,
            'GIRLS'
        );

        $this->withToken($this->tokenFor($user))
            ->postJson(self::ENDPOINT, [
                'learner_id' => $learner->id,
                'bed_id' => $bed->id,
                'allocated_by' => (string) Str::uuid(),
                'allocation_date' => '2000-01-01',
                'release_date' => '2000-01-02',
                'active' => false,
                'id' => (string) Str::uuid(),
                'created_at' => '2000-01-01 00:00:00',
                'updated_at' => '2000-01-01 00:00:00',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'allocated_by',
                'allocation_date',
                'release_date',
                'active',
                'id',
                'created_at',
                'updated_at',
            ]);

        $this->assertSame(
            0,
            DB::table('bed_allocations')
                ->where('school_id', $school->id)
                ->count()
        );
    }

    public function test_foreign_tenant_learner_identifier_fails_closed(): void
    {
        [$school, $user] = $this->authorizedSchoolUser();

        $foreignSchool = $this->school();

        $foreignLearner = $this->learner(
            $foreignSchool,
            'Female',
            'boarder',
            'active',
            true
        );

        $bed = $this->boardingBed(
            $school,
            'GIRLS'
        );

        $this->withToken($this->tokenFor($user))
            ->postJson(self::ENDPOINT, [
                'learner_id' => $foreignLearner->id,
                'bed_id' => $bed->id,
            ])
            ->assertNotFound();

        $this->assertSame(
            0,
            DB::table('bed_allocations')
                ->where('school_id', $school->id)
                ->count()
        );
    }

    public function test_foreign_tenant_bed_identifier_fails_closed(): void
    {
        [$school, $user] = $this->authorizedSchoolUser();

        $foreignSchool = $this->school();

        $learner = $this->learner(
            $school,
            'Male',
            'boarder',
            'active',
            true
        );

        $foreignBed = $this->boardingBed(
            $foreignSchool,
            'BOYS'
        );

        $this->withToken($this->tokenFor($user))
            ->postJson(self::ENDPOINT, [
                'learner_id' => $learner->id,
                'bed_id' => $foreignBed->id,
            ])
            ->assertNotFound();

        $this->assertSame(
            0,
            DB::table('bed_allocations')
                ->where('school_id', $school->id)
                ->count()
        );
    }

    public function test_day_scholar_cannot_receive_bed_through_http(): void
    {
        [$school, $user] = $this->authorizedSchoolUser();

        $learner = $this->learner(
            $school,
            'Female',
            'day_scholar',
            'active',
            true
        );

        $bed = $this->boardingBed(
            $school,
            'GIRLS'
        );

        $this->withToken($this->tokenFor($user))
            ->postJson(self::ENDPOINT, [
                'learner_id' => $learner->id,
                'bed_id' => $bed->id,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'learner_id',
            ]);
    }

    public function test_inactive_learner_cannot_receive_bed_through_http(): void
    {
        [$school, $user] = $this->authorizedSchoolUser();

        $learner = $this->learner(
            $school,
            'Female',
            'boarder',
            null,
            false
        );

        $bed = $this->boardingBed(
            $school,
            'GIRLS'
        );

        $this->withToken($this->tokenFor($user))
            ->postJson(self::ENDPOINT, [
                'learner_id' => $learner->id,
                'bed_id' => $bed->id,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'learner_id',
            ]);
    }

    public function test_terminal_lifecycle_learner_cannot_receive_bed_through_http(): void
    {
        [$school, $user] = $this->authorizedSchoolUser();

        $learner = $this->learner(
            $school,
            'Female',
            'boarder',
            'withdrawn',
            false
        );

        /*
         * A terminal lifecycle learner is correctly inactive by
         * database invariant. The HTTP contract only needs to prove
         * that this valid terminal state cannot receive a bed.
         */
        $bed = $this->boardingBed(
            $school,
            'GIRLS'
        );

        $this->withToken($this->tokenFor($user))
            ->postJson(self::ENDPOINT, [
                'learner_id' => $learner->id,
                'bed_id' => $bed->id,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'learner_id',
            ]);
    }

    public function test_boys_and_girls_hostel_separation_is_enforced_through_http(): void
    {
        [$school, $user] = $this->authorizedSchoolUser();

        $femaleLearner = $this->learner(
            $school,
            'Female',
            'boarder',
            'active',
            true
        );

        $boysBed = $this->boardingBed(
            $school,
            'BOYS'
        );

        $this->withToken($this->tokenFor($user))
            ->postJson(self::ENDPOINT, [
                'learner_id' => $femaleLearner->id,
                'bed_id' => $boysBed->id,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'bed_id',
            ]);

        $maleLearner = $this->learner(
            $school,
            'Male',
            'boarder',
            'active',
            true
        );

        $girlsBed = $this->boardingBed(
            $school,
            'GIRLS'
        );

        $this->withToken($this->tokenFor($user))
            ->postJson(self::ENDPOINT, [
                'learner_id' => $maleLearner->id,
                'bed_id' => $girlsBed->id,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'bed_id',
            ]);

        $this->assertSame(
            0,
            DB::table('bed_allocations')
                ->where('school_id', $school->id)
                ->count()
        );
    }

    public function test_same_learner_cannot_receive_second_active_bed_through_http(): void
    {
        [$school, $user] = $this->authorizedSchoolUser();

        $learner = $this->learner(
            $school,
            'Female',
            'boarder',
            'active',
            true
        );

        $bedOne = $this->boardingBed(
            $school,
            'GIRLS',
            'ONE'
        );

        $bedTwo = $this->boardingBed(
            $school,
            'GIRLS',
            'TWO'
        );

        $this->withToken($this->tokenFor($user))
            ->postJson(self::ENDPOINT, [
                'learner_id' => $learner->id,
                'bed_id' => $bedOne->id,
            ])
            ->assertCreated();

        $this->withToken($this->tokenFor($user))
            ->postJson(self::ENDPOINT, [
                'learner_id' => $learner->id,
                'bed_id' => $bedTwo->id,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'learner_id',
            ]);

        $this->assertSame(
            1,
            DB::table('bed_allocations')
                ->where('school_id', $school->id)
                ->where('learner_id', $learner->id)
                ->where('active', true)
                ->count()
        );
    }

    public function test_same_bed_cannot_receive_second_active_learner_through_http(): void
    {
        [$school, $user] = $this->authorizedSchoolUser();

        $learnerOne = $this->learner(
            $school,
            'Male',
            'boarder',
            'active',
            true
        );

        $learnerTwo = $this->learner(
            $school,
            'Male',
            'boarder',
            'active',
            true
        );

        $bed = $this->boardingBed(
            $school,
            'BOYS'
        );

        $this->withToken($this->tokenFor($user))
            ->postJson(self::ENDPOINT, [
                'learner_id' => $learnerOne->id,
                'bed_id' => $bed->id,
            ])
            ->assertCreated();

        $this->withToken($this->tokenFor($user))
            ->postJson(self::ENDPOINT, [
                'learner_id' => $learnerTwo->id,
                'bed_id' => $bed->id,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'bed_id',
            ]);

        $this->assertSame(
            1,
            DB::table('bed_allocations')
                ->where('school_id', $school->id)
                ->where('bed_id', $bed->id)
                ->where('active', true)
                ->count()
        );
    }

    public function test_server_controls_actor_and_school_local_allocation_date(): void
    {
        CarbonImmutable::setTestNow(
            CarbonImmutable::parse(
                '2026-09-01 21:30:00',
                'UTC'
            )
        );

        try {
            [$school, $user] = $this->authorizedSchoolUser();

            $learner = $this->learner(
                $school,
                'Male',
                'boarder',
                'active',
                true
            );

            $bed = $this->boardingBed(
                $school,
                'BOYS'
            );

            $response = $this->withToken(
                $this->tokenFor($user)
            )->postJson(self::ENDPOINT, [
                'learner_id' => $learner->id,
                'bed_id' => $bed->id,
            ]);

            $response
                ->assertCreated()
                ->assertJsonPath(
                    'data.allocation_date',
                    '2026-09-02'
                )
                ->assertJsonPath(
                    'data.allocated_by',
                    $user->id
                );

            $this->assertDatabaseHas(
                'bed_allocations',
                [
                    'school_id' => $school->id,
                    'learner_id' => $learner->id,
                    'bed_id' => $bed->id,
                    'allocation_date' => '2026-09-02',
                    'allocated_by' => $user->id,
                    'active' => true,
                ]
            );
        } finally {
            CarbonImmutable::setTestNow();
        }
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
            ->where(
                'permission_name',
                'manage_boarding'
            )
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
            'school_name' => 'Allocation HTTP '.Str::upper(
                Str::random(8)
            ),
            'school_code' => 'BAH-'.Str::upper(
                Str::random(8)
            ),
            'short_name' => 'BAH',
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

    private function user(
        School $school
    ): User {
        $role = Role::query()->create([
            'id' => (string) Str::uuid(),
            'role_name' => 'Allocation HTTP '.Str::upper(
                Str::random(8)
            ),
            'description' => 'Bed allocation HTTP test role',
            'active' => true,
        ]);

        return User::query()->create([
            'id' => (string) Str::uuid(),
            'school_id' => $school->id,
            'role_id' => $role->id,
            'first_name' => 'Boarding',
            'last_name' => 'HTTP',
            'username' => 'boarding_http_'.Str::lower(
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

    private function learner(
        School $school,
        string $gender,
        string $modeOfStudy,
        ?string $lifecycleStatus,
        bool $active
    ): Learner {
        $gradeId = (string) Str::uuid();
        $streamId = (string) Str::uuid();

        DB::table('grades')->insert([
            'id' => $gradeId,
            'school_id' => $school->id,
            'grade_name' => 'Grade '.Str::upper(
                Str::random(8)
            ),
            'grade_order' => random_int(
                2001,
                1000000
            ),
            'active' => true,
            'created_at' => now(),
        ]);

        DB::table('streams')->insert([
            'id' => $streamId,
            'school_id' => $school->id,
            'grade_id' => $gradeId,
            'stream_name' => 'Stream '.Str::upper(
                Str::random(8)
            ),
            'active' => true,
            'created_at' => now(),
        ]);

        $learner = new Learner;

        $learner->id = (string) Str::uuid();
        $learner->school_id = $school->id;
        $learner->admission_no = 'HTTP-'.Str::upper(
            Str::random(8)
        );
        $learner->first_name = 'Allocation';
        $learner->last_name = 'Learner';
        $learner->gender = $gender;
        $learner->grade_id = $gradeId;
        $learner->stream_id = $streamId;
        $learner->admission_date = now()->toDateString();
        $learner->active = $active;
        $learner->lifecycle_status = $lifecycleStatus;
        $learner->mode_of_study = $modeOfStudy;

        $learner->save();

        return $learner->refresh();
    }

    private function boardingBed(
        School $school,
        string $hostelType,
        string $suffix = ''
    ): HostelBed {
        $suffix = $suffix !== ''
            ? '-'.$suffix
            : '-'.Str::upper(Str::random(4));

        $hostel = new Hostel;

        $hostel->school_id = $school->id;
        $hostel->hostel_name = 'Hostel '
            .$hostelType
            .$suffix;
        $hostel->hostel_type = $hostelType;
        $hostel->capacity = 20;
        $hostel->active = true;
        $hostel->is_deleted = false;
        $hostel->save();

        $room = new HostelRoom;

        $room->school_id = $school->id;
        $room->hostel_id = $hostel->id;
        $room->room_name = 'Room'.$suffix;
        $room->floor_number = null;
        $room->capacity = 10;
        $room->active = true;
        $room->is_deleted = false;
        $room->save();

        $bed = new HostelBed;

        $bed->school_id = $school->id;
        $bed->room_id = $room->id;
        $bed->bed_number = 'BED'.$suffix;
        $bed->active = true;
        $bed->is_deleted = false;
        $bed->save();

        return $bed->refresh();
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
