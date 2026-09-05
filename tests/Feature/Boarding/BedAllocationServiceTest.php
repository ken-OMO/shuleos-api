<?php

declare(strict_types=1);

namespace Tests\Feature\Boarding;

use App\Models\BedAllocationHistory;
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

    public function test_failed_release_history_creation_rolls_back_completely(): void
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
            'BOYS',
            'RELEASE-ROLLBACK'
        );

        $allocation = $this->service->allocate(
            (string) $school->id,
            (string) $learner->id,
            (string) $bed->id,
            (string) $user->id
        );

        $originalBedId = (string) $allocation->bed_id;

        BedAllocationHistory::creating(
            static function (BedAllocationHistory $history) use (
                $allocation
            ): void {
                if (
                    $history->event_type === 'release'
                    && (string) $history->source_allocation_id
                        === (string) $allocation->id
                ) {
                    throw new \RuntimeException(
                        'Forced release history failure.'
                    );
                }
            }
        );

        try {
            $this->service->release(
                (string) $school->id,
                (string) $allocation->id,
                (string) $user->id,
                'Rollback proof'
            );

            $this->fail(
                'Release succeeded despite forced history failure.'
            );
        } catch (\RuntimeException $exception) {
            $this->assertSame(
                'Forced release history failure.',
                $exception->getMessage()
            );
        }

        $allocation->refresh();

        $this->assertSame(
            'active',
            $allocation->status
        );
        $this->assertTrue($allocation->active);
        $this->assertNull($allocation->release_date);
        $this->assertSame(
            $originalBedId,
            (string) $allocation->bed_id
        );

        $this->assertSame(
            1,
            DB::table('bed_allocations')
                ->where('school_id', $school->id)
                ->where('learner_id', $learner->id)
                ->where('active', true)
                ->count()
        );

        $this->assertSame(
            0,
            BedAllocationHistory::query()
                ->where('school_id', $school->id)
                ->where(
                    'source_allocation_id',
                    $allocation->id
                )
                ->where('event_type', 'release')
                ->count()
        );
    }

    public function test_foreign_tenant_cannot_release_allocation(): void
    {
        $ownerSchool = $this->school();
        $ownerUser = $this->user($ownerSchool);
        $learner = $this->learner(
            $ownerSchool,
            'Male',
            'boarder',
            'active',
            true
        );
        $bed = $this->boardingBed(
            $ownerSchool,
            'BOYS',
            'FOREIGN-SCHOOL-RELEASE'
        );

        $allocation = $this->service->allocate(
            (string) $ownerSchool->id,
            (string) $learner->id,
            (string) $bed->id,
            (string) $ownerUser->id
        );

        $foreignSchool = $this->school();
        $foreignUser = $this->user($foreignSchool);

        try {
            $this->service->release(
                (string) $foreignSchool->id,
                (string) $allocation->id,
                (string) $foreignUser->id,
                'Cross-tenant attempt'
            );

            $this->fail(
                'Foreign tenant released another school allocation.'
            );
        } catch (ModelNotFoundException) {
            $this->assertTrue(true);
        }

        $allocation->refresh();

        $this->assertSame(
            (string) $ownerSchool->id,
            (string) $allocation->school_id
        );
        $this->assertSame(
            'active',
            $allocation->status
        );
        $this->assertTrue($allocation->active);
        $this->assertNull($allocation->release_date);

        $this->assertSame(
            0,
            BedAllocationHistory::query()
                ->where(
                    'source_allocation_id',
                    $allocation->id
                )
                ->where('event_type', 'release')
                ->count()
        );
    }

    public function test_foreign_tenant_actor_cannot_release_local_allocation(): void
    {
        $school = $this->school();
        $ownerUser = $this->user($school);
        $learner = $this->learner(
            $school,
            'Male',
            'boarder',
            'active',
            true
        );
        $bed = $this->boardingBed(
            $school,
            'BOYS',
            'FOREIGN-ACTOR-RELEASE'
        );

        $allocation = $this->service->allocate(
            (string) $school->id,
            (string) $learner->id,
            (string) $bed->id,
            (string) $ownerUser->id
        );

        $foreignSchool = $this->school();
        $foreignUser = $this->user($foreignSchool);

        try {
            $this->service->release(
                (string) $school->id,
                (string) $allocation->id,
                (string) $foreignUser->id,
                'Foreign actor attempt'
            );

            $this->fail(
                'Foreign tenant actor released local allocation.'
            );
        } catch (ModelNotFoundException) {
            $this->assertTrue(true);
        }

        $allocation->refresh();

        $this->assertSame(
            'active',
            $allocation->status
        );
        $this->assertTrue($allocation->active);
        $this->assertNull($allocation->release_date);

        $this->assertSame(
            0,
            BedAllocationHistory::query()
                ->where('school_id', $school->id)
                ->where(
                    'source_allocation_id',
                    $allocation->id
                )
                ->where('event_type', 'release')
                ->count()
        );
    }

    public function test_active_bed_allocation_can_be_released_with_history(): void
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
            'BOYS',
            'RELEASE'
        );

        $allocation = $this->service->allocate(
            (string) $school->id,
            (string) $learner->id,
            (string) $bed->id,
            (string) $user->id
        );

        $released = $this->service->release(
            (string) $school->id,
            (string) $allocation->id,
            (string) $user->id,
            'End of boarding stay'
        );

        $this->assertSame(
            'released',
            $released->status
        );
        $this->assertFalse($released->active);
        $this->assertNotNull($released->release_date);
        $this->assertSame(
            (string) $bed->id,
            (string) $released->bed_id
        );
        $this->assertSame(
            (string) $learner->id,
            (string) $released->learner_id
        );

        $history = BedAllocationHistory::query()
            ->where('school_id', $school->id)
            ->where(
                'source_allocation_id',
                $allocation->id
            )
            ->sole();

        $this->assertSame(
            'release',
            $history->event_type
        );
        $this->assertSame(
            'active',
            $history->from_status
        );
        $this->assertSame(
            'released',
            $history->to_status
        );
        $this->assertNull(
            $history->destination_allocation_id
        );
        $this->assertSame(
            (string) $learner->id,
            (string) $history->learner_id
        );
        $this->assertSame(
            (string) $user->id,
            (string) $history->changed_by
        );
        $this->assertSame(
            'End of boarding stay',
            $history->reason
        );
        $this->assertTrue(Str::isUuid((string) $history->id));
        $this->assertTrue(Str::isUuid((string) $history->event_id));
        $this->assertNotSame(
            (string) $history->id,
            (string) $history->event_id
        );
        $this->assertNotNull($history->effective_date);
        $this->assertNotNull($history->changed_at);
        $this->assertNotNull($history->created_at);

        $this->assertSame(
            1,
            BedAllocationHistory::query()
                ->where(
                    'source_allocation_id',
                    $allocation->id
                )
                ->count()
        );
    }

    public function test_released_allocation_cannot_be_released_twice(): void
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
            'BOYS',
            'DOUBLE-RELEASE'
        );

        $allocation = $this->service->allocate(
            (string) $school->id,
            (string) $learner->id,
            (string) $bed->id,
            (string) $user->id
        );

        $this->service->release(
            (string) $school->id,
            (string) $allocation->id,
            (string) $user->id,
            'First release'
        );

        try {
            $this->service->release(
                (string) $school->id,
                (string) $allocation->id,
                (string) $user->id,
                'Second release'
            );

            $this->fail(
                'A released allocation was released twice.'
            );
        } catch (ValidationException $exception) {
            $this->assertSame(
                [
                    'Only an active bed allocation can be released.',
                ],
                $exception->errors()['allocation_id']
            );
        }

        $allocation->refresh();

        $this->assertSame(
            'released',
            $allocation->status
        );
        $this->assertFalse($allocation->active);
        $this->assertNotNull($allocation->release_date);

        $this->assertSame(
            1,
            BedAllocationHistory::query()
                ->where(
                    'source_allocation_id',
                    $allocation->id
                )
                ->where('event_type', 'release')
                ->count()
        );
    }

    public function test_existing_bed_can_be_released_after_each_terminal_learner_lifecycle(): void
    {
        foreach (['withdrawn', 'transferred', 'graduated'] as $lifecycleStatus) {
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
                'BOYS',
                'TERMINAL-'.strtoupper($lifecycleStatus)
            );

            $allocation = $this->service->allocate(
                (string) $school->id,
                (string) $learner->id,
                (string) $bed->id,
                (string) $user->id
            );

            DB::table('learners')
                ->where('id', $learner->id)
                ->where('school_id', $school->id)
                ->update([
                    'lifecycle_status' => $lifecycleStatus,
                    'active' => false,
                    'updated_at' => now(),
                ]);

            $released = $this->service->release(
                (string) $school->id,
                (string) $allocation->id,
                (string) $user->id,
                'Cleanup after '.$lifecycleStatus
            );

            $this->assertSame(
                'released',
                $released->status,
                'Release failed after learner lifecycle '.$lifecycleStatus
            );

            $this->assertFalse(
                $released->active,
                'Allocation remained active after '.$lifecycleStatus
            );

            $this->assertNotNull(
                $released->release_date,
                'Release date missing after '.$lifecycleStatus
            );

            $this->assertDatabaseHas('bed_allocation_history', [
                'school_id' => $school->id,
                'learner_id' => $learner->id,
                'event_type' => 'release',
                'source_allocation_id' => $allocation->id,
                'destination_allocation_id' => null,
                'from_status' => 'active',
                'to_status' => 'released',
                'reason' => 'Cleanup after '.$lifecycleStatus,
                'changed_by' => $user->id,
            ]);

            $this->assertSame(
                0,
                DB::table('bed_allocations')
                    ->where('school_id', $school->id)
                    ->where('learner_id', $learner->id)
                    ->where('active', true)
                    ->count()
            );
        }
    }

    public function test_existing_allocation_can_be_released_after_bed_is_retired(): void
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
            'BOYS',
            'RETIRED-BED'
        );

        $allocation = $this->service->allocate(
            (string) $school->id,
            (string) $learner->id,
            (string) $bed->id,
            (string) $user->id
        );

        DB::table('hostel_beds')
            ->where('id', $bed->id)
            ->where('school_id', $school->id)
            ->update([
                'active' => false,
                'is_deleted' => true,
                'updated_at' => now(),
            ]);

        $released = $this->service->release(
            (string) $school->id,
            (string) $allocation->id,
            (string) $user->id,
            'Cleanup after bed retirement'
        );

        $this->assertSame('released', $released->status);
        $this->assertFalse($released->active);
        $this->assertNotNull($released->release_date);

        $this->assertDatabaseHas('bed_allocation_history', [
            'school_id' => $school->id,
            'learner_id' => $learner->id,
            'event_type' => 'release',
            'source_allocation_id' => $allocation->id,
            'destination_allocation_id' => null,
            'from_status' => 'active',
            'to_status' => 'released',
            'reason' => 'Cleanup after bed retirement',
            'changed_by' => $user->id,
        ]);
    }

    public function test_existing_allocation_can_be_released_after_room_is_retired(): void
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
            'BOYS',
            'RETIRED-ROOM'
        );

        $allocation = $this->service->allocate(
            (string) $school->id,
            (string) $learner->id,
            (string) $bed->id,
            (string) $user->id
        );

        $roomId = DB::table('hostel_beds')
            ->where('id', $bed->id)
            ->where('school_id', $school->id)
            ->value('room_id');

        $this->assertNotNull($roomId);

        DB::table('hostel_rooms')
            ->where('id', $roomId)
            ->where('school_id', $school->id)
            ->update([
                'active' => false,
                'is_deleted' => true,
                'updated_at' => now(),
            ]);

        $released = $this->service->release(
            (string) $school->id,
            (string) $allocation->id,
            (string) $user->id,
            'Cleanup after room retirement'
        );

        $this->assertSame('released', $released->status);
        $this->assertFalse($released->active);
        $this->assertNotNull($released->release_date);

        $this->assertDatabaseHas('bed_allocation_history', [
            'school_id' => $school->id,
            'learner_id' => $learner->id,
            'event_type' => 'release',
            'source_allocation_id' => $allocation->id,
            'destination_allocation_id' => null,
            'from_status' => 'active',
            'to_status' => 'released',
            'reason' => 'Cleanup after room retirement',
            'changed_by' => $user->id,
        ]);
    }

    public function test_existing_allocation_can_be_released_after_hostel_is_retired(): void
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
            'BOYS',
            'RETIRED-HOSTEL'
        );

        $allocation = $this->service->allocate(
            (string) $school->id,
            (string) $learner->id,
            (string) $bed->id,
            (string) $user->id
        );

        $roomId = DB::table('hostel_beds')
            ->where('id', $bed->id)
            ->where('school_id', $school->id)
            ->value('room_id');

        $this->assertNotNull($roomId);

        $hostelId = DB::table('hostel_rooms')
            ->where('id', $roomId)
            ->where('school_id', $school->id)
            ->value('hostel_id');

        $this->assertNotNull($hostelId);

        DB::table('hostels')
            ->where('id', $hostelId)
            ->where('school_id', $school->id)
            ->update([
                'active' => false,
                'is_deleted' => true,
                'updated_at' => now(),
            ]);

        $released = $this->service->release(
            (string) $school->id,
            (string) $allocation->id,
            (string) $user->id,
            'Cleanup after hostel retirement'
        );

        $this->assertSame('released', $released->status);
        $this->assertFalse($released->active);
        $this->assertNotNull($released->release_date);

        $this->assertDatabaseHas('bed_allocation_history', [
            'school_id' => $school->id,
            'learner_id' => $learner->id,
            'event_type' => 'release',
            'source_allocation_id' => $allocation->id,
            'destination_allocation_id' => null,
            'from_status' => 'active',
            'to_status' => 'released',
            'reason' => 'Cleanup after hostel retirement',
            'changed_by' => $user->id,
        ]);
    }

    public function test_release_reason_cannot_exceed_database_bound(): void
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
            'BOYS',
            'REASON-BOUND'
        );

        $allocation = $this->service->allocate(
            (string) $school->id,
            (string) $learner->id,
            (string) $bed->id,
            (string) $user->id
        );

        try {
            $this->service->release(
                (string) $school->id,
                (string) $allocation->id,
                (string) $user->id,
                str_repeat('R', 501)
            );

            $this->fail(
                'Release accepted a reason beyond the DB bound.'
            );
        } catch (ValidationException $exception) {
            $this->assertSame(
                [
                    'The release reason may not exceed 500 characters.',
                ],
                $exception->errors()['reason']
            );
        }

        $allocation->refresh();

        $this->assertSame(
            'active',
            $allocation->status
        );
        $this->assertTrue($allocation->active);
        $this->assertNull($allocation->release_date);

        $this->assertSame(
            0,
            BedAllocationHistory::query()
                ->where(
                    'source_allocation_id',
                    $allocation->id
                )
                ->count()
        );
    }

    public function test_release_reason_accepts_exact_database_bound(): void
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
            'BOYS',
            'RELEASE-REASON-500'
        );

        $allocation = $this->service->allocate(
            (string) $school->id,
            (string) $learner->id,
            (string) $bed->id,
            (string) $user->id
        );

        $reason = str_repeat('R', 500);

        $released = $this->service->release(
            (string) $school->id,
            (string) $allocation->id,
            (string) $user->id,
            $reason
        );

        $this->assertSame('released', $released->status);
        $this->assertFalse($released->active);

        $history = BedAllocationHistory::query()
            ->where('school_id', $school->id)
            ->where('source_allocation_id', $allocation->id)
            ->sole();

        $this->assertSame($reason, $history->reason);
        $this->assertSame(500, mb_strlen((string) $history->reason));
    }

    public function test_transfer_reason_rejects_over_database_bound(): void
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

        $sourceBed = $this->boardingBed(
            $school,
            'BOYS',
            'TRANSFER-REASON-501-SOURCE'
        );

        $destinationBed = $this->boardingBed(
            $school,
            'BOYS',
            'TRANSFER-REASON-501-DEST'
        );

        $allocation = $this->service->allocate(
            (string) $school->id,
            (string) $learner->id,
            (string) $sourceBed->id,
            (string) $user->id
        );

        try {
            $this->service->transfer(
                (string) $school->id,
                (string) $allocation->id,
                (string) $destinationBed->id,
                (string) $user->id,
                str_repeat('T', 501)
            );

            $this->fail(
                'Transfer reason longer than 500 characters must fail.'
            );
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey(
                'reason',
                $exception->errors()
            );
        }

        $source = $allocation->fresh();

        $this->assertSame('active', $source->status);
        $this->assertTrue($source->active);
        $this->assertNull($source->release_date);

        $this->assertSame(
            0,
            DB::table('bed_allocation_history')
                ->where('school_id', $school->id)
                ->where('source_allocation_id', $allocation->id)
                ->count()
        );
    }

    public function test_transfer_reason_accepts_exact_database_bound(): void
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

        $sourceBed = $this->boardingBed(
            $school,
            'BOYS',
            'TRANSFER-REASON-500-SOURCE'
        );

        $destinationBed = $this->boardingBed(
            $school,
            'BOYS',
            'TRANSFER-REASON-500-DEST'
        );

        $allocation = $this->service->allocate(
            (string) $school->id,
            (string) $learner->id,
            (string) $sourceBed->id,
            (string) $user->id
        );

        $reason = str_repeat('T', 500);

        $destination = $this->service->transfer(
            (string) $school->id,
            (string) $allocation->id,
            (string) $destinationBed->id,
            (string) $user->id,
            $reason
        );

        $this->assertSame('active', $destination->status);
        $this->assertTrue($destination->active);

        $history = BedAllocationHistory::query()
            ->where('school_id', $school->id)
            ->where('source_allocation_id', $allocation->id)
            ->sole();

        $this->assertSame('transfer', $history->event_type);
        $this->assertSame($reason, $history->reason);
        $this->assertSame(500, mb_strlen((string) $history->reason));
    }

    public function test_release_date_and_history_use_school_timezone(): void
    {
        $school = $this->school();

        /*
         * Nairobi is already on the next calendar day while UTC
         * remains on the previous day at this instant.
         */
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
            'BOYS',
            'RELEASE-TIMEZONE'
        );

        $allocation = $this->service->allocate(
            (string) $school->id,
            (string) $learner->id,
            (string) $bed->id,
            (string) $user->id
        );

        CarbonImmutable::setTestNow(
            CarbonImmutable::parse(
                '2026-09-04 22:30:00',
                'UTC'
            )
        );

        try {
            $released = $this->service->release(
                (string) $school->id,
                (string) $allocation->id,
                (string) $user->id,
                'Timezone proof'
            );
        } finally {
            CarbonImmutable::setTestNow();
        }

        $this->assertSame(
            '2026-09-05',
            $released->release_date?->format('Y-m-d')
        );

        $history = BedAllocationHistory::query()
            ->where('school_id', $school->id)
            ->where('source_allocation_id', $allocation->id)
            ->sole();

        $this->assertSame(
            '2026-09-05',
            $history->effective_date?->format('Y-m-d')
        );
    }

    public function test_bed_allocation_history_database_rejects_duplicate_event_id(): void
    {
        $school = $this->school();
        $user = $this->user($school);

        $firstLearner = $this->learner(
            $school,
            'Male',
            'boarder',
            'active',
            true
        );

        $secondLearner = $this->learner(
            $school,
            'Male',
            'boarder',
            'active',
            true
        );

        $firstBed = $this->boardingBed(
            $school,
            'BOYS',
            'EVENT-ID-UNIQUE-ONE'
        );

        $secondBed = $this->boardingBed(
            $school,
            'BOYS',
            'EVENT-ID-UNIQUE-TWO'
        );

        $firstAllocation = $this->service->allocate(
            (string) $school->id,
            (string) $firstLearner->id,
            (string) $firstBed->id,
            (string) $user->id
        );

        $secondAllocation = $this->service->allocate(
            (string) $school->id,
            (string) $secondLearner->id,
            (string) $secondBed->id,
            (string) $user->id
        );

        $this->service->release(
            (string) $school->id,
            (string) $firstAllocation->id,
            (string) $user->id,
            'First event'
        );

        $this->service->release(
            (string) $school->id,
            (string) $secondAllocation->id,
            (string) $user->id,
            'Second event'
        );

        $firstHistory = DB::table('bed_allocation_history')
            ->where('source_allocation_id', $firstAllocation->id)
            ->first();

        $secondHistory = DB::table('bed_allocation_history')
            ->where('source_allocation_id', $secondAllocation->id)
            ->first();

        $this->assertNotNull($firstHistory);
        $this->assertNotNull($secondHistory);

        $this->assertNotSame(
            (string) $firstHistory->event_id,
            (string) $secondHistory->event_id
        );

        DB::statement(
            'SAVEPOINT bed_history_duplicate_event_id'
        );

        try {
            DB::table('bed_allocation_history')
                ->insert([
                    'id' => (string) Str::uuid(),
                    'school_id' => $school->id,
                    'learner_id' => $secondLearner->id,
                    'event_id' => $firstHistory->event_id,
                    'event_type' => 'release',
                    'source_allocation_id' => $secondAllocation->id,
                    'destination_allocation_id' => null,
                    'from_status' => 'active',
                    'to_status' => 'released',
                    'effective_date' => $secondHistory->effective_date,
                    'reason' => 'Duplicate event id attempt',
                    'changed_by' => $user->id,
                    'changed_at' => now(),
                    'created_at' => now(),
                ]);

            DB::statement(
                'RELEASE SAVEPOINT bed_history_duplicate_event_id'
            );

            $this->fail(
                'PostgreSQL must reject duplicate lifecycle event_id.'
            );
        } catch (QueryException $exception) {
            DB::statement(
                'ROLLBACK TO SAVEPOINT bed_history_duplicate_event_id'
            );

            DB::statement(
                'RELEASE SAVEPOINT bed_history_duplicate_event_id'
            );

            $this->assertSame(
                '23505',
                (string) ($exception->errorInfo[0] ?? '')
            );
        }

        $this->assertSame(
            1,
            DB::table('bed_allocation_history')
                ->where('event_id', $firstHistory->event_id)
                ->count()
        );

        $this->assertSame(
            2,
            DB::table('bed_allocation_history')
                ->where('school_id', $school->id)
                ->count()
        );
    }

    public function test_bed_allocation_history_model_rejects_update(): void
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
            'BOYS',
            'MODEL-IMMUTABLE-UPDATE'
        );

        $allocation = $this->service->allocate(
            (string) $school->id,
            (string) $learner->id,
            (string) $bed->id,
            (string) $user->id
        );

        $this->service->release(
            (string) $school->id,
            (string) $allocation->id,
            (string) $user->id,
            'Original model history reason'
        );

        $history = BedAllocationHistory::query()
            ->where('school_id', $school->id)
            ->where('source_allocation_id', $allocation->id)
            ->sole();

        $history->reason = 'ILLEGAL MODEL UPDATE';

        try {
            $history->save();

            $this->fail(
                'Eloquent must fail fast when history is updated.'
            );
        } catch (\LogicException $exception) {
            $this->assertSame(
                'Bed allocation history is immutable.',
                $exception->getMessage()
            );
        }

        $persisted = DB::table('bed_allocation_history')
            ->where('id', $history->id)
            ->sole();

        $this->assertSame(
            'Original model history reason',
            $persisted->reason
        );
    }

    public function test_bed_allocation_history_model_rejects_delete(): void
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
            'BOYS',
            'MODEL-IMMUTABLE-DELETE'
        );

        $allocation = $this->service->allocate(
            (string) $school->id,
            (string) $learner->id,
            (string) $bed->id,
            (string) $user->id
        );

        $this->service->release(
            (string) $school->id,
            (string) $allocation->id,
            (string) $user->id,
            'Original model delete reason'
        );

        $history = BedAllocationHistory::query()
            ->where('school_id', $school->id)
            ->where('source_allocation_id', $allocation->id)
            ->sole();

        try {
            $history->delete();

            $this->fail(
                'Eloquent must fail fast when history is deleted.'
            );
        } catch (\LogicException $exception) {
            $this->assertSame(
                'Bed allocation history is immutable.',
                $exception->getMessage()
            );
        }

        $persisted = DB::table('bed_allocation_history')
            ->where('id', $history->id)
            ->sole();

        $this->assertSame(
            'Original model delete reason',
            $persisted->reason
        );
    }

    public function test_bed_allocation_history_database_rejects_direct_update(): void
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
            'BOYS',
            'IMMUTABLE-UPDATE'
        );

        $allocation = $this->service->allocate(
            (string) $school->id,
            (string) $learner->id,
            (string) $bed->id,
            (string) $user->id
        );

        $this->service->release(
            (string) $school->id,
            (string) $allocation->id,
            (string) $user->id,
            'Immutable original reason'
        );

        $history = DB::table('bed_allocation_history')
            ->where('school_id', $school->id)
            ->where('source_allocation_id', $allocation->id)
            ->sole();

        $before = json_encode($history, JSON_THROW_ON_ERROR);

        DB::statement(
            'SAVEPOINT bed_history_immutable_update'
        );

        try {
            DB::table('bed_allocation_history')
                ->where('id', $history->id)
                ->update([
                    'reason' => 'ILLEGAL HISTORY UPDATE',
                ]);

            DB::statement(
                'RELEASE SAVEPOINT bed_history_immutable_update'
            );

            $this->fail(
                'PostgreSQL must reject UPDATE of bed allocation history.'
            );
        } catch (QueryException $exception) {
            DB::statement(
                'ROLLBACK TO SAVEPOINT bed_history_immutable_update'
            );
            DB::statement(
                'RELEASE SAVEPOINT bed_history_immutable_update'
            );

            $this->assertSame(
                '55000',
                $exception->errorInfo[0] ?? null
            );
            $this->assertStringContainsString(
                'bed_allocation_history is append-only',
                $exception->getMessage()
            );
        }

        $after = DB::table('bed_allocation_history')
            ->where('id', $history->id)
            ->sole();

        $this->assertSame(
            $before,
            json_encode($after, JSON_THROW_ON_ERROR)
        );
        $this->assertSame(
            'Immutable original reason',
            $after->reason
        );
    }

    public function test_bed_allocation_history_database_rejects_direct_delete(): void
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
            'BOYS',
            'IMMUTABLE-DELETE'
        );

        $allocation = $this->service->allocate(
            (string) $school->id,
            (string) $learner->id,
            (string) $bed->id,
            (string) $user->id
        );

        $this->service->release(
            (string) $school->id,
            (string) $allocation->id,
            (string) $user->id,
            'Immutable delete proof'
        );

        $history = DB::table('bed_allocation_history')
            ->where('school_id', $school->id)
            ->where('source_allocation_id', $allocation->id)
            ->sole();

        $before = json_encode($history, JSON_THROW_ON_ERROR);

        DB::statement(
            'SAVEPOINT bed_history_immutable_delete'
        );

        try {
            DB::table('bed_allocation_history')
                ->where('id', $history->id)
                ->delete();

            DB::statement(
                'RELEASE SAVEPOINT bed_history_immutable_delete'
            );

            $this->fail(
                'PostgreSQL must reject DELETE of bed allocation history.'
            );
        } catch (QueryException $exception) {
            DB::statement(
                'ROLLBACK TO SAVEPOINT bed_history_immutable_delete'
            );
            DB::statement(
                'RELEASE SAVEPOINT bed_history_immutable_delete'
            );

            $this->assertSame(
                '55000',
                $exception->errorInfo[0] ?? null
            );
            $this->assertStringContainsString(
                'bed_allocation_history is append-only',
                $exception->getMessage()
            );
        }

        $after = DB::table('bed_allocation_history')
            ->where('id', $history->id)
            ->sole();

        $this->assertSame(
            $before,
            json_encode($after, JSON_THROW_ON_ERROR)
        );
        $this->assertSame(
            'Immutable delete proof',
            $after->reason
        );
    }

    public function test_active_boarder_can_be_transferred_atomically_with_correlated_history(): void
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

        $sourceBed = $this->boardingBed(
            $school,
            'BOYS',
            'TRANSFER-SOURCE'
        );

        $destinationBed = $this->boardingBed(
            $school,
            'BOYS',
            'TRANSFER-DESTINATION'
        );

        $source = $this->service->allocate(
            (string) $school->id,
            (string) $learner->id,
            (string) $sourceBed->id,
            (string) $user->id
        );

        $destination = $this->service->transfer(
            (string) $school->id,
            (string) $source->id,
            (string) $destinationBed->id,
            (string) $user->id,
            'Move to destination bed'
        );

        $sourceAfterTransfer = DB::table('bed_allocations')
            ->where('school_id', $school->id)
            ->where('id', $source->id)
            ->first();

        $destinationAfterTransfer = DB::table('bed_allocations')
            ->where('school_id', $school->id)
            ->where('id', $destination->id)
            ->first();

        $this->assertNotNull($sourceAfterTransfer);
        $this->assertNotNull($destinationAfterTransfer);

        $this->assertSame(
            'transferred',
            $sourceAfterTransfer->status
        );

        $this->assertFalse(
            (bool) $sourceAfterTransfer->active
        );

        $this->assertNotNull(
            $sourceAfterTransfer->release_date
        );

        $this->assertSame(
            (string) $sourceBed->id,
            (string) $sourceAfterTransfer->bed_id
        );

        $this->assertSame(
            'active',
            $destinationAfterTransfer->status
        );

        $this->assertTrue(
            (bool) $destinationAfterTransfer->active
        );

        $this->assertNull(
            $destinationAfterTransfer->release_date
        );

        $this->assertSame(
            (string) $destinationBed->id,
            (string) $destinationAfterTransfer->bed_id
        );

        $this->assertSame(
            (string) $learner->id,
            (string) $destinationAfterTransfer->learner_id
        );

        $this->assertSame(
            (string) $user->id,
            (string) $destinationAfterTransfer->allocated_by
        );

        $this->assertSame(
            2,
            DB::table('bed_allocations')
                ->where('school_id', $school->id)
                ->where('learner_id', $learner->id)
                ->count()
        );

        $this->assertSame(
            1,
            DB::table('bed_allocations')
                ->where('school_id', $school->id)
                ->where('learner_id', $learner->id)
                ->where('active', true)
                ->count()
        );

        $historyRows = DB::table('bed_allocation_history')
            ->where('school_id', $school->id)
            ->where('learner_id', $learner->id)
            ->get();

        $this->assertCount(1, $historyRows);

        $history = $historyRows->first();

        $this->assertNotNull($history);
        $this->assertTrue(Str::isUuid((string) $history->id));
        $this->assertTrue(Str::isUuid((string) $history->event_id));
        $this->assertNotSame(
            (string) $history->id,
            (string) $history->event_id
        );

        $this->assertSame(
            'transfer',
            $history->event_type
        );

        $this->assertSame(
            'active',
            $history->from_status
        );

        $this->assertSame(
            'transferred',
            $history->to_status
        );

        $this->assertSame(
            (string) $source->id,
            (string) $history->source_allocation_id
        );

        $this->assertSame(
            (string) $destination->id,
            (string) $history->destination_allocation_id
        );

        $this->assertSame(
            'Move to destination bed',
            $history->reason
        );

        $this->assertSame(
            (string) $user->id,
            (string) $history->changed_by
        );

        $this->assertSame(
            (string) $sourceAfterTransfer->release_date,
            (string) $history->effective_date
        );

        $this->assertSame(
            (string) $destinationAfterTransfer->allocation_date,
            (string) $history->effective_date
        );
    }

    public function test_failed_transfer_rolls_back_source_allocation_completely(): void
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

        $sourceBed = $this->boardingBed(
            $school,
            'BOYS',
            'SOURCE'
        );

        $destinationBed = $this->boardingBed(
            $school,
            'BOYS',
            'DESTINATION'
        );

        $source = $this->service->allocate(
            (string) $school->id,
            (string) $learner->id,
            (string) $sourceBed->id,
            (string) $user->id
        );

        $source = $source->refresh();

        $this->assertSame('active', $source->status);
        $this->assertTrue($source->active);
        $this->assertNull($source->release_date);

        try {
            BedAllocationHistory::creating(
                static function (): void {
                    throw new \RuntimeException(
                        'Forced history creation failure.'
                    );
                }
            );

            $this->service->transfer(
                (string) $school->id,
                (string) $source->id,
                (string) $destinationBed->id,
                (string) $user->id,
                'Atomic rollback proof'
            );

            $this->fail(
                'Forced transfer failure unexpectedly succeeded.'
            );
        } catch (\RuntimeException $exception) {
            $this->assertSame(
                'Forced history creation failure.',
                $exception->getMessage()
            );
        }

        $sourceAfterFailure = DB::table('bed_allocations')
            ->where('id', $source->id)
            ->where('school_id', $school->id)
            ->first();

        $this->assertNotNull($sourceAfterFailure);

        $this->assertSame(
            'active',
            $sourceAfterFailure->status
        );

        $this->assertTrue(
            (bool) $sourceAfterFailure->active
        );

        $this->assertNull(
            $sourceAfterFailure->release_date
        );

        $this->assertSame(
            (string) $sourceBed->id,
            (string) $sourceAfterFailure->bed_id
        );

        $this->assertSame(
            1,
            DB::table('bed_allocations')
                ->where('school_id', $school->id)
                ->where('learner_id', $learner->id)
                ->count()
        );

        $this->assertSame(
            0,
            DB::table('bed_allocations')
                ->where('school_id', $school->id)
                ->where('learner_id', $learner->id)
                ->where('bed_id', $destinationBed->id)
                ->count()
        );

        $this->assertSame(
            0,
            DB::table('bed_allocation_history')
                ->where('school_id', $school->id)
                ->where('learner_id', $learner->id)
                ->count()
        );
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

    public function test_transfer_concurrency_contract_uses_deterministic_resource_locking(): void
    {
        $service = file_get_contents(
            app_path('Services/Boarding/BedAllocationService.php')
        );

        $this->assertIsString($service);

        $this->assertStringContainsString(
            'private function lockTransferBeds(',
            $service
        );

        $this->assertStringContainsString(
            'private function lockTransferRooms(',
            $service
        );

        $this->assertStringContainsString(
            'private function lockTransferHostels(',
            $service
        );

        $this->assertGreaterThanOrEqual(
            3,
            substr_count(
                $service,
                "->orderBy('id')"
            )
        );

        $this->assertGreaterThanOrEqual(
            6,
            substr_count(
                $service,
                '->lockForUpdate()'
            )
        );

        $this->assertStringContainsString(
            '$lockedBeds = $this->lockTransferBeds(',
            $service
        );

        $this->assertStringContainsString(
            '$lockedRooms = $this->lockTransferRooms(',
            $service
        );

        $this->assertStringContainsString(
            '$lockedHostels = $this->lockTransferHostels(',
            $service
        );
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
            'status' => 'active',
            'allocated_by' => $user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
