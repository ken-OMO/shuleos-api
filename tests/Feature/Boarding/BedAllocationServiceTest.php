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
use App\Services\Boarding\BedAllocationService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class BedAllocationServiceTest extends TestCase
{
    use DatabaseTransactions;

    private BedAllocationService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(BedAllocationService::class);
    }

    public function test_active_male_boarder_can_be_allocated_to_boys_bed(): void
    {
        $school = $this->school();
        $user = $this->user($school);

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

        $allocation = $this->service->allocate(
            (string) $school->id,
            (string) $learner->id,
            (string) $bed->id,

            (string) $user->id
        );

        $this->assertSame(
            (string) $school->id,
            (string) $allocation->school_id
        );

        $this->assertSame(
            (string) $learner->id,
            (string) $allocation->learner_id
        );

        $this->assertSame(
            (string) $bed->id,
            (string) $allocation->bed_id
        );

        $this->assertTrue($allocation->active);
        $this->assertNull($allocation->release_date);

        $this->assertSame(
            (string) $user->id,
            (string) $allocation->allocated_by
        );
    }

    public function test_active_female_boarder_can_be_allocated_to_girls_bed(): void
    {
        $school = $this->school();
        $user = $this->user($school);

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

        $allocation = $this->service->allocate(
            (string) $school->id,
            (string) $learner->id,
            (string) $bed->id,

            (string) $user->id
        );

        $this->assertTrue($allocation->active);

        $this->assertSame(
            (string) $learner->id,
            (string) $allocation->learner_id
        );
    }

    public function test_male_learner_cannot_be_allocated_to_girls_hostel(): void
    {
        $school = $this->school();
        $user = $this->user($school);

        $learner = $this->learner(
            $school,
            'Male',
            'boarder',
            'active',
            true
        );

        $bed = $this->boardingBed(
            $school,
            'GIRLS'
        );

        $this->expectValidationException(
            'bed_id',
            function () use (
                $school,
                $learner,
                $bed,
                $user
            ): void {
                $this->service->allocate(
                    (string) $school->id,
                    (string) $learner->id,
                    (string) $bed->id,

                    (string) $user->id
                );
            }
        );

        $this->assertSame(
            0,
            DB::table('bed_allocations')->count()
        );
    }

    public function test_female_learner_cannot_be_allocated_to_boys_hostel(): void
    {
        $school = $this->school();
        $user = $this->user($school);

        $learner = $this->learner(
            $school,
            'Female',
            'boarder',
            'active',
            true
        );

        $bed = $this->boardingBed(
            $school,
            'BOYS'
        );

        $this->expectValidationException(
            'bed_id',
            function () use (
                $school,
                $learner,
                $bed,
                $user
            ): void {
                $this->service->allocate(
                    (string) $school->id,
                    (string) $learner->id,
                    (string) $bed->id,

                    (string) $user->id
                );
            }
        );
    }

    public function test_day_scholar_cannot_receive_bed(): void
    {
        $school = $this->school();
        $user = $this->user($school);

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

        $this->expectValidationException(
            'learner_id',
            function () use (
                $school,
                $learner,
                $bed,
                $user
            ): void {
                $this->service->allocate(
                    (string) $school->id,
                    (string) $learner->id,
                    (string) $bed->id,

                    (string) $user->id
                );
            }
        );
    }

    public function test_inactive_learner_cannot_receive_bed(): void
    {
        $school = $this->school();
        $user = $this->user($school);

        $learner = $this->learner(
            $school,
            'Female',
            'boarder',
            'withdrawn',
            false
        );

        $bed = $this->boardingBed(
            $school,
            'GIRLS'
        );

        $this->expectValidationException(
            'learner_id',
            function () use (
                $school,
                $learner,
                $bed,
                $user
            ): void {
                $this->service->allocate(
                    (string) $school->id,
                    (string) $learner->id,
                    (string) $bed->id,

                    (string) $user->id
                );
            }
        );
    }

    public function test_terminal_lifecycle_learner_cannot_receive_bed(): void
    {
        $school = $this->school();
        $user = $this->user($school);

        $learner = $this->learner(
            $school,
            'Female',
            'boarder',
            'withdrawn',
            false
        );

        $bed = $this->boardingBed(
            $school,
            'GIRLS'
        );

        $this->expectValidationException(
            'learner_id',
            function () use (
                $school,
                $learner,
                $bed,
                $user
            ): void {
                $this->service->allocate(
                    (string) $school->id,
                    (string) $learner->id,
                    (string) $bed->id,

                    (string) $user->id
                );
            }
        );
    }

    public function test_foreign_tenant_learner_fails_closed(): void
    {
        $schoolA = $this->school();
        $schoolB = $this->school();

        $userA = $this->user($schoolA);

        $learnerB = $this->learner(
            $schoolB,
            'Female',
            'boarder',
            'active',
            true
        );

        $bedA = $this->boardingBed(
            $schoolA,
            'GIRLS'
        );

        try {
            $this->service->allocate(
                (string) $schoolA->id,
                (string) $learnerB->id,
                (string) $bedA->id,

                (string) $userA->id
            );

            $this->fail(
                'Cross-tenant learner allocation must fail closed.'
            );
        } catch (ModelNotFoundException) {
            $this->assertTrue(true);
        }

        $this->assertSame(
            0,
            DB::table('bed_allocations')->count()
        );
    }

    public function test_foreign_tenant_bed_fails_closed(): void
    {
        $schoolA = $this->school();
        $schoolB = $this->school();

        $userA = $this->user($schoolA);

        $learnerA = $this->learner(
            $schoolA,
            'Female',
            'boarder',
            'active',
            true
        );

        $bedB = $this->boardingBed(
            $schoolB,
            'GIRLS'
        );

        try {
            $this->service->allocate(
                (string) $schoolA->id,
                (string) $learnerA->id,
                (string) $bedB->id,

                (string) $userA->id
            );

            $this->fail(
                'Cross-tenant bed allocation must fail closed.'
            );
        } catch (ModelNotFoundException) {
            $this->assertTrue(true);
        }

        $this->assertSame(
            0,
            DB::table('bed_allocations')->count()
        );
    }

    public function test_learner_cannot_have_two_active_beds(): void
    {
        $school = $this->school();
        $user = $this->user($school);

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
            'A'
        );

        $bedTwo = $this->boardingBed(
            $school,
            'GIRLS',
            'B'
        );

        $this->service->allocate(
            (string) $school->id,
            (string) $learner->id,
            (string) $bedOne->id,

            (string) $user->id
        );

        $this->expectValidationException(
            'learner_id',
            function () use (
                $school,
                $learner,
                $bedTwo,
                $user
            ): void {
                $this->service->allocate(
                    (string) $school->id,
                    (string) $learner->id,
                    (string) $bedTwo->id,

                    (string) $user->id
                );
            }
        );

        $this->assertSame(
            1,
            DB::table('bed_allocations')
                ->where('school_id', $school->id)
                ->where('learner_id', $learner->id)
                ->where('active', true)
                ->count()
        );
    }

    public function test_bed_cannot_have_two_active_learners(): void
    {
        $school = $this->school();
        $user = $this->user($school);

        $learnerOne = $this->learner(
            $school,
            'Female',
            'boarder',
            'active',
            true
        );

        $learnerTwo = $this->learner(
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

        $this->service->allocate(
            (string) $school->id,
            (string) $learnerOne->id,
            (string) $bed->id,

            (string) $user->id
        );

        $this->expectValidationException(
            'bed_id',
            function () use (
                $school,
                $learnerTwo,
                $bed,
                $user
            ): void {
                $this->service->allocate(
                    (string) $school->id,
                    (string) $learnerTwo->id,
                    (string) $bed->id,

                    (string) $user->id
                );
            }
        );

        $this->assertSame(
            1,
            DB::table('bed_allocations')
                ->where('school_id', $school->id)
                ->where('bed_id', $bed->id)
                ->where('active', true)
                ->count()
        );
    }

    public function test_inactive_bed_cannot_receive_learner(): void
    {
        $school = $this->school();
        $user = $this->user($school);

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

        DB::table('hostel_beds')
            ->where('id', $bed->id)
            ->update([
                'active' => false,
            ]);

        $this->expectValidationException(
            'bed_id',
            function () use (
                $school,
                $learner,
                $bed,
                $user
            ): void {
                $this->service->allocate(
                    (string) $school->id,
                    (string) $learner->id,
                    (string) $bed->id,

                    (string) $user->id
                );
            }
        );
    }

    public function test_inactive_room_cannot_receive_allocation_through_child_bed(): void
    {
        $school = $this->school();
        $user = $this->user($school);

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

        DB::table('hostel_rooms')
            ->where('id', $bed->room_id)
            ->update([
                'active' => false,
            ]);

        $this->expectValidationException(
            'bed_id',
            function () use (
                $school,
                $learner,
                $bed,
                $user
            ): void {
                $this->service->allocate(
                    (string) $school->id,
                    (string) $learner->id,
                    (string) $bed->id,

                    (string) $user->id
                );
            }
        );
    }

    public function test_inactive_hostel_cannot_receive_allocation_through_child_bed(): void
    {
        $school = $this->school();
        $user = $this->user($school);

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

        $room = HostelRoom::query()
            ->withoutGlobalScopes()
            ->findOrFail($bed->room_id);

        DB::table('hostels')
            ->where('id', $room->hostel_id)
            ->update([
                'active' => false,
            ]);

        $this->expectValidationException(
            'bed_id',
            function () use (
                $school,
                $learner,
                $bed,
                $user
            ): void {
                $this->service->allocate(
                    (string) $school->id,
                    (string) $learner->id,
                    (string) $bed->id,

                    (string) $user->id
                );
            }
        );
    }

    public function test_database_rejects_second_active_allocation_for_same_bed(): void
    {
        $school = $this->school();
        $user = $this->user($school);

        $learnerOne = $this->learner(
            $school,
            'Female',
            'boarder',
            'active',
            true
        );

        $learnerTwo = $this->learner(
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

        $this->rawAllocation(
            $school,
            $learnerOne,
            $bed,
            $user
        );

        try {
            $this->rawAllocation(
                $school,
                $learnerTwo,
                $bed,
                $user
            );

            $this->fail(
                'Database must reject two active learners in one bed.'
            );
        } catch (QueryException $exception) {
            $this->assertStringContainsString(
                'bed_allocations_active_bed_unique',
                $exception->getMessage()
            );
        }
    }

    public function test_database_rejects_second_active_bed_for_same_learner(): void
    {
        $school = $this->school();
        $user = $this->user($school);

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
            'A'
        );

        $bedTwo = $this->boardingBed(
            $school,
            'GIRLS',
            'B'
        );

        $this->rawAllocation(
            $school,
            $learner,
            $bedOne,
            $user
        );

        try {
            $this->rawAllocation(
                $school,
                $learner,
                $bedTwo,
                $user
            );

            $this->fail(
                'Database must reject two active beds for one learner.'
            );
        } catch (QueryException $exception) {
            $this->assertStringContainsString(
                'bed_allocations_active_learner_unique',
                $exception->getMessage()
            );
        }
    }

    public function test_foreign_tenant_actor_fails_closed(): void
    {
        $schoolA = $this->school();
        $schoolB = $this->school();

        $userB = $this->user($schoolB);

        $learnerA = $this->learner(
            $schoolA,
            'Male',
            'boarder',
            'active',
            true
        );

        $bedA = $this->boardingBed(
            $schoolA,
            'BOYS'
        );

        $this->assertNotSame(
            (string) $schoolA->id,
            (string) $schoolB->id
        );

        try {
            $this->service->allocate(
                (string) $schoolA->id,
                (string) $learnerA->id,
                (string) $bedA->id,
                (string) $userB->id
            );

            $this->fail(
                'Foreign-tenant actor unexpectedly created a bed allocation.'
            );
        } catch (ModelNotFoundException) {
            $this->assertDatabaseMissing(
                'bed_allocations',
                [
                    'school_id' => $schoolA->id,
                    'learner_id' => $learnerA->id,
                    'bed_id' => $bedA->id,
                    'active' => true,
                ]
            );
        }
    }

    public function test_allocation_date_uses_school_timezone(): void
    {
        CarbonImmutable::setTestNow(
            CarbonImmutable::parse(
                '2026-09-01 21:30:00',
                'UTC'
            )
        );

        try {
            $school = $this->school();

            $school->timezone = 'Africa/Nairobi';
            $school->save();

            $user = $this->user($school);

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

            $allocation = $this->service->allocate(
                (string) $school->id,
                (string) $learner->id,
                (string) $bed->id,
                (string) $user->id
            );

            $this->assertSame(
                '2026-09-02',
                $allocation->allocation_date->toDateString()
            );

            $this->assertSame(
                (string) $user->id,
                (string) $allocation->allocated_by
            );

            $this->assertDatabaseHas(
                'bed_allocations',
                [
                    'id' => $allocation->id,
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

    public function test_allocation_concurrency_contract_uses_resource_locks_and_database_backstops(): void
    {
        $service = file_get_contents(
            app_path('Services/Boarding/BedAllocationService.php')
        );

        $migration = file_get_contents(
            database_path(
                'migrations/2026_08_31_220000_harden_boarding_hostel_foundation.php'
            )
        );

        $this->assertStringNotContainsString(
            'private function lockSchool(',
            $service
        );

        $this->assertStringContainsString(
            "->where('school_id', ".'$schoolId'.')',
            $service
        );

        $this->assertStringContainsString(
            '->lockForUpdate()',
            $service
        );

        $this->assertStringContainsString(
            'bed_allocations_active_bed_unique',
            $migration
        );

        $this->assertStringContainsString(
            'bed_allocations_active_learner_unique',
            $migration
        );

        $this->assertStringContainsString(
            "'23505'",
            $service
        );
    }

    private function expectValidationException(
        string $field,
        callable $callback
    ): void {
        try {
            $callback();

            $this->fail(
                "Expected validation failure for {$field}."
            );
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey(
                $field,
                $exception->errors()
            );
        }
    }

    private function school(): School
    {
        return School::query()->create([
            'id' => (string) Str::uuid(),
            'school_name' => 'Allocation '.Str::upper(
                Str::random(8)
            ),
            'school_code' => 'BA-'.Str::upper(
                Str::random(8)
            ),
            'short_name' => 'BA',
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
            'role_name' => 'Allocation Test '.Str::upper(
                Str::random(8)
            ),
            'description' => 'Bed allocation service test role',
            'active' => true,
        ]);

        return User::query()->create([
            'id' => (string) Str::uuid(),
            'school_id' => $school->id,
            'role_id' => $role->id,
            'first_name' => 'Boarding',
            'last_name' => 'Allocation',
            'username' => 'allocation_'.Str::lower(
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

    private function learner(
        School $school,
        string $gender,
        string $modeOfStudy,
        string $lifecycleStatus,
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
                1,
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
        $learner->admission_no = 'ALLOC-'.Str::upper(
            Str::random(8)
        );
        $learner->first_name = 'Allocation';
        $learner->last_name = 'Learner';
        $learner->gender = $gender;
        $learner->grade_id = $gradeId;
        $learner->stream_id = $streamId;
        $learner->admission_date = now()->toDateString();
        $learner->active = $active;

        /*
         * These lifecycle/mode fields are server-owned in the
         * production workflow, so assign them explicitly.
         */
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
        $hostel->hostel_name = 'Hostel '.$hostelType.$suffix;
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

    private function rawAllocation(
        School $school,
        Learner $learner,
        HostelBed $bed,
        User $user
    ): void {
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
            'updated_at' => now(),
        ]);
    }
}
