<?php

declare(strict_types=1);

namespace Tests\Feature\TeacherDuty;

use App\Models\Role;
use App\Models\School;
use App\Models\TeacherDutyPeriod;
use App\Models\User;
use App\Services\TeacherDuty\TeacherDutyRosterService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LogicException;
use Tests\Support\Database\TeacherBuilder;
use Tests\TestCase;

class TeacherDutyRosterServiceTest extends TestCase
{
    use DatabaseTransactions;

    private TeacherDutyRosterService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(TeacherDutyRosterService::class);
    }

    public function test_period_creation_sets_server_owned_current_lifecycle_fields(): void
    {
        $school = $this->school();
        $actor = $this->user($school);

        $period = $this->service->createPeriod(
            (string) $school->id,
            '2026-09-07',
            '2026-09-11',
            null,
            (string) $actor->id
        );

        $this->assertSame((string) $school->id, (string) $period->school_id);
        $this->assertSame('2026-09-07', $period->start_date?->toDateString());
        $this->assertSame('2026-09-11', $period->end_date?->toDateString());
        $this->assertNull($period->academic_week_id);
        $this->assertTrue($period->active);
        $this->assertSame((string) $actor->id, (string) $period->created_by);
        $this->assertNull($period->ended_by);
        $this->assertNull($period->ended_at);
        $this->assertNull($period->end_reason);

        $this->assertDatabaseHas('teacher_duty_periods', [
            'id' => $period->id,
            'school_id' => $school->id,
            'active' => true,
            'created_by' => $actor->id,
            'ended_by' => null,
            'ended_at' => null,
            'end_reason' => null,
        ]);
    }

    public function test_period_dates_must_be_strict_yyyy_mm_dd_and_end_cannot_precede_start(): void
    {
        $school = $this->school();
        $actor = $this->user($school);

        foreach (
            [
                ['07-09-2026', '2026-09-11', 'start_date'],
                ['2026-09-07', '11/09/2026', 'end_date'],
                ['2026-02-30', '2026-03-01', 'start_date'],
                ['2026-09-11', '2026-09-07', 'end_date'],
            ] as [$start, $end, $field]
        ) {
            $this->expectValidationField(
                $field,
                fn () => $this->service->createPeriod(
                    (string) $school->id,
                    $start,
                    $end,
                    null,
                    (string) $actor->id
                )
            );
        }

        $this->assertSame(
            0,
            DB::table('teacher_duty_periods')
                ->where('school_id', $school->id)
                ->count()
        );
    }

    public function test_overlapping_and_non_monday_periods_are_allowed(): void
    {
        $school = $this->school();
        $actor = $this->user($school);

        $first = $this->service->createPeriod(
            (string) $school->id,
            '2026-09-08',
            '2026-09-12',
            null,
            (string) $actor->id
        );

        $second = $this->service->createPeriod(
            (string) $school->id,
            '2026-09-10',
            '2026-09-14',
            null,
            (string) $actor->id
        );

        $this->assertNotSame((string) $first->id, (string) $second->id);

        $this->assertSame(
            2,
            DB::table('teacher_duty_periods')
                ->where('school_id', $school->id)
                ->where('active', true)
                ->count()
        );
    }

    public function test_optional_same_school_academic_week_is_metadata_and_may_be_inactive(): void
    {
        $school = $this->school();
        $actor = $this->user($school);

        $week = $this->academicWeek(
            $school,
            [
                'active' => false,
                'start_date' => '2026-08-01',
                'end_date' => '2026-08-07',
            ]
        );

        $period = $this->service->createPeriod(
            (string) $school->id,
            '2026-09-07',
            '2026-09-11',
            (string) $week->id,
            (string) $actor->id
        );

        $this->assertSame(
            (string) $week->id,
            (string) $period->academic_week_id
        );

        $this->assertSame('2026-09-07', $period->start_date?->toDateString());
        $this->assertSame('2026-09-11', $period->end_date?->toDateString());
    }

    public function test_foreign_academic_week_is_rejected(): void
    {
        $schoolA = $this->school();
        $schoolB = $this->school();

        $actorA = $this->user($schoolA);
        $weekB = $this->academicWeek($schoolB);

        $this->expectValidationField(
            'academic_week_id',
            fn () => $this->service->createPeriod(
                (string) $schoolA->id,
                '2026-09-07',
                '2026-09-11',
                (string) $weekB->id,
                (string) $actorA->id
            )
        );

        $this->assertSame(
            0,
            DB::table('teacher_duty_periods')
                ->where('school_id', $schoolA->id)
                ->count()
        );
    }

    public function test_foreign_inactive_deleted_and_suspended_actors_are_rejected(): void
    {
        $schoolA = $this->school();
        $schoolB = $this->school();

        $foreign = $this->user($schoolB);

        $this->expectValidationField(
            'actor',
            fn () => $this->service->createPeriod(
                (string) $schoolA->id,
                '2026-09-07',
                '2026-09-11',
                null,
                (string) $foreign->id
            )
        );

        foreach (
            [
                ['active' => false],
                ['is_deleted' => true],
                ['suspended_at' => now()],
            ] as $state
        ) {
            $actor = $this->user($schoolA);

            DB::table('users')
                ->where('id', $actor->id)
                ->update($state);

            $this->expectValidationField(
                'actor',
                fn () => $this->service->createPeriod(
                    (string) $schoolA->id,
                    '2026-09-07',
                    '2026-09-11',
                    null,
                    (string) $actor->id
                )
            );
        }

        $this->assertSame(
            0,
            DB::table('teacher_duty_periods')
                ->where('school_id', $schoolA->id)
                ->count()
        );
    }

    public function test_assignment_requires_same_school_teacher_specialization(): void
    {
        $school = $this->school();
        $actor = $this->user($school);
        $ordinaryUser = $this->user($school);

        $period = $this->periodFixture($school, $actor);

        $this->expectValidationField(
            'teacher_id',
            fn () => $this->service->assignTeacher(
                (string) $school->id,
                (string) $period->id,
                (string) $ordinaryUser->id,
                (string) $actor->id
            )
        );

        $this->assertSame(
            0,
            DB::table('teacher_duty_assignments')
                ->where('school_id', $school->id)
                ->count()
        );
    }

    public function test_foreign_inactive_and_deleted_teachers_are_rejected(): void
    {
        $schoolA = $this->school();
        $schoolB = $this->school();

        $actorA = $this->user($schoolA);
        $periodA = $this->periodFixture($schoolA, $actorA);

        $foreignTeacher = $this->teacher($schoolB);

        $this->expectValidationField(
            'teacher_id',
            fn () => $this->service->assignTeacher(
                (string) $schoolA->id,
                (string) $periodA->id,
                (string) $foreignTeacher->id,
                (string) $actorA->id
            )
        );

        foreach (
            [
                ['active' => false],
                ['is_deleted' => true],
            ] as $state
        ) {
            $teacher = $this->teacher($schoolA);

            DB::table('teachers')
                ->where('id', $teacher->id)
                ->update($state);

            $this->expectValidationField(
                'teacher_id',
                fn () => $this->service->assignTeacher(
                    (string) $schoolA->id,
                    (string) $periodA->id,
                    (string) $teacher->id,
                    (string) $actorA->id
                )
            );
        }

        $this->assertSame(
            0,
            DB::table('teacher_duty_assignments')
                ->where('school_id', $schoolA->id)
                ->count()
        );
    }

    public function test_teacher_linked_user_must_remain_same_school_active_not_deleted_and_not_suspended(): void
    {
        $school = $this->school();
        $actor = $this->user($school);
        $period = $this->periodFixture($school, $actor);

        foreach (
            [
                ['active' => false],
                ['is_deleted' => true],
                ['suspended_at' => now()],
            ] as $state
        ) {
            $teacher = $this->teacher($school);

            DB::table('users')
                ->where('id', $teacher->user_id)
                ->update($state);

            $this->expectValidationField(
                'teacher_id',
                fn () => $this->service->assignTeacher(
                    (string) $school->id,
                    (string) $period->id,
                    (string) $teacher->id,
                    (string) $actor->id
                )
            );
        }

        $this->assertSame(
            0,
            DB::table('teacher_duty_assignments')
                ->where('school_id', $school->id)
                ->count()
        );
    }

    public function test_multiple_different_teachers_may_share_one_duty_period(): void
    {
        $school = $this->school();
        $actor = $this->user($school);
        $period = $this->periodFixture($school, $actor);

        $first = $this->teacher($school);
        $second = $this->teacher($school);
        $third = $this->teacher($school);

        foreach ([$first, $second, $third] as $teacher) {
            $this->service->assignTeacher(
                (string) $school->id,
                (string) $period->id,
                (string) $teacher->id,
                (string) $actor->id
            );
        }

        $this->assertSame(
            3,
            DB::table('teacher_duty_assignments')
                ->where('school_id', $school->id)
                ->where('duty_period_id', $period->id)
                ->where('active', true)
                ->count()
        );
    }

    public function test_duplicate_current_teacher_assignment_is_domain_validation_failure(): void
    {
        $school = $this->school();
        $actor = $this->user($school);
        $teacher = $this->teacher($school);
        $period = $this->periodFixture($school, $actor);

        $this->service->assignTeacher(
            (string) $school->id,
            (string) $period->id,
            (string) $teacher->id,
            (string) $actor->id
        );

        $this->expectValidationField(
            'teacher_id',
            fn () => $this->service->assignTeacher(
                (string) $school->id,
                (string) $period->id,
                (string) $teacher->id,
                (string) $actor->id
            )
        );

        $this->assertSame(
            1,
            DB::table('teacher_duty_assignments')
                ->where('school_id', $school->id)
                ->where('duty_period_id', $period->id)
                ->where('teacher_id', $teacher->id)
                ->where('active', true)
                ->count()
        );
    }

    public function test_database_partial_unique_index_remains_authoritative(): void
    {
        $school = $this->school();
        $actor = $this->user($school);
        $teacher = $this->teacher($school);
        $period = $this->periodFixture($school, $actor);

        $firstId = (string) Str::uuid();

        DB::table('teacher_duty_assignments')->insert([
            'id' => $firstId,
            'school_id' => $school->id,
            'duty_period_id' => $period->id,
            'teacher_id' => $teacher->id,
            'active' => true,
            'assigned_by' => $actor->id,
            'ended_by' => null,
            'ended_at' => null,
            'end_reason' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        try {
            DB::transaction(function () use (
                $school,
                $actor,
                $teacher,
                $period
            ): void {
                DB::table('teacher_duty_assignments')->insert([
                    'id' => (string) Str::uuid(),
                    'school_id' => $school->id,
                    'duty_period_id' => $period->id,
                    'teacher_id' => $teacher->id,
                    'active' => true,
                    'assigned_by' => $actor->id,
                    'ended_by' => null,
                    'ended_at' => null,
                    'end_reason' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            });

            $this->fail(
                'PostgreSQL accepted duplicate current Teacher duty identity.'
            );
        } catch (QueryException $exception) {
            $this->assertSame(
                '23505',
                $exception->errorInfo[0] ?? null
            );

            $this->assertStringContainsString(
                'teacher_duty_assignments_active_identity_unique',
                $exception->getMessage()
            );
        }

        $this->assertDatabaseHas('teacher_duty_assignments', [
            'id' => $firstId,
            'active' => true,
        ]);
    }

    public function test_teacher_cannot_be_assigned_to_ended_period(): void
    {
        $school = $this->school();
        $actor = $this->user($school);
        $teacher = $this->teacher($school);

        $period = $this->periodFixture($school, $actor);

        $this->service->endPeriod(
            (string) $school->id,
            (string) $period->id,
            (string) $actor->id
        );

        $this->expectValidationField(
            'period_id',
            fn () => $this->service->assignTeacher(
                (string) $school->id,
                (string) $period->id,
                (string) $teacher->id,
                (string) $actor->id
            )
        );
    }

    public function test_assignment_end_is_terminal_and_historical_reassignment_creates_new_episode(): void
    {
        $school = $this->school();
        $actor = $this->user($school);
        $teacher = $this->teacher($school);
        $period = $this->periodFixture($school, $actor);

        $first = $this->service->assignTeacher(
            (string) $school->id,
            (string) $period->id,
            (string) $teacher->id,
            (string) $actor->id
        );

        $ended = $this->service->endAssignment(
            (string) $school->id,
            (string) $first->id,
            (string) $actor->id,
            '  Rotation completed  '
        );

        $this->assertFalse($ended->active);
        $this->assertSame((string) $actor->id, (string) $ended->ended_by);
        $this->assertNotNull($ended->ended_at);
        $this->assertSame('Rotation completed', $ended->end_reason);

        $this->expectValidationField(
            'assignment',
            fn () => $this->service->endAssignment(
                (string) $school->id,
                (string) $first->id,
                (string) $actor->id
            )
        );

        $second = $this->service->assignTeacher(
            (string) $school->id,
            (string) $period->id,
            (string) $teacher->id,
            (string) $actor->id
        );

        $this->assertNotSame((string) $first->id, (string) $second->id);

        $this->assertSame(
            2,
            DB::table('teacher_duty_assignments')
                ->where('school_id', $school->id)
                ->where('duty_period_id', $period->id)
                ->where('teacher_id', $teacher->id)
                ->count()
        );

        $this->assertSame(
            1,
            DB::table('teacher_duty_assignments')
                ->where('school_id', $school->id)
                ->where('duty_period_id', $period->id)
                ->where('teacher_id', $teacher->id)
                ->where('active', true)
                ->count()
        );
    }

    public function test_end_reason_is_trimmed_blank_becomes_null_and_500_character_boundary_is_enforced(): void
    {
        $school = $this->school();
        $actor = $this->user($school);
        $period = $this->periodFixture($school, $actor);

        $firstTeacher = $this->teacher($school);

        $first = $this->service->assignTeacher(
            (string) $school->id,
            (string) $period->id,
            (string) $firstTeacher->id,
            (string) $actor->id
        );

        $this->expectValidationField(
            'reason',
            fn () => $this->service->endAssignment(
                (string) $school->id,
                (string) $first->id,
                (string) $actor->id,
                str_repeat('R', 501)
            )
        );

        $this->assertDatabaseHas('teacher_duty_assignments', [
            'id' => $first->id,
            'active' => true,
            'end_reason' => null,
        ]);

        $reason = str_repeat('R', 500);

        $endedFirst = $this->service->endAssignment(
            (string) $school->id,
            (string) $first->id,
            (string) $actor->id,
            $reason
        );

        $this->assertSame($reason, $endedFirst->end_reason);

        $secondTeacher = $this->teacher($school);

        $second = $this->service->assignTeacher(
            (string) $school->id,
            (string) $period->id,
            (string) $secondTeacher->id,
            (string) $actor->id
        );

        $endedSecond = $this->service->endAssignment(
            (string) $school->id,
            (string) $second->id,
            (string) $actor->id,
            '   '
        );

        $this->assertNull($endedSecond->end_reason);
    }

    public function test_ending_period_atomically_closes_all_current_child_assignments_with_same_evidence(): void
    {
        $school = $this->school();
        $actor = $this->user($school);
        $period = $this->periodFixture($school, $actor);

        $teacherA = $this->teacher($school);
        $teacherB = $this->teacher($school);

        $assignmentA = $this->service->assignTeacher(
            (string) $school->id,
            (string) $period->id,
            (string) $teacherA->id,
            (string) $actor->id
        );

        $assignmentB = $this->service->assignTeacher(
            (string) $school->id,
            (string) $period->id,
            (string) $teacherB->id,
            (string) $actor->id
        );

        $endedPeriod = $this->service->endPeriod(
            (string) $school->id,
            (string) $period->id,
            (string) $actor->id,
            '  Duty week completed  '
        );

        $this->assertFalse($endedPeriod->active);
        $this->assertSame('Duty week completed', $endedPeriod->end_reason);
        $this->assertSame(
            (string) $actor->id,
            (string) $endedPeriod->ended_by
        );
        $this->assertNotNull($endedPeriod->ended_at);

        foreach ([$assignmentA, $assignmentB] as $assignment) {
            $row = DB::table('teacher_duty_assignments')
                ->where('id', $assignment->id)
                ->first();

            $this->assertNotNull($row);
            $this->assertFalse((bool) $row->active);
            $this->assertSame((string) $actor->id, (string) $row->ended_by);
            $this->assertSame('Duty week completed', $row->end_reason);
            $this->assertNotNull($row->ended_at);

            $periodEndedAt = DB::table('teacher_duty_periods')
                ->where('id', $endedPeriod->id)
                ->value('ended_at');

            $this->assertSame(
                CarbonImmutable::parse(
                    (string) $periodEndedAt
                )->getTimestamp(),
                CarbonImmutable::parse(
                    (string) $row->ended_at
                )->getTimestamp()
            );
        }

        $this->assertSame(
            0,
            DB::table('teacher_duty_assignments')
                ->where('school_id', $school->id)
                ->where('duty_period_id', $period->id)
                ->where('active', true)
                ->count()
        );
    }

    public function test_ending_period_preserves_assignments_already_historical(): void
    {
        $school = $this->school();
        $actor = $this->user($school);
        $period = $this->periodFixture($school, $actor);

        $historicalTeacher = $this->teacher($school);
        $currentTeacher = $this->teacher($school);

        $historical = $this->service->assignTeacher(
            (string) $school->id,
            (string) $period->id,
            (string) $historicalTeacher->id,
            (string) $actor->id
        );

        $historical = $this->service->endAssignment(
            (string) $school->id,
            (string) $historical->id,
            (string) $actor->id,
            'Teacher rotation'
        );

        $oldEndedBy = (string) $historical->ended_by;
        $oldEndedAt = (string) DB::table('teacher_duty_assignments')
            ->where('id', $historical->id)
            ->value('ended_at');
        $oldReason = $historical->end_reason;

        $this->service->assignTeacher(
            (string) $school->id,
            (string) $period->id,
            (string) $currentTeacher->id,
            (string) $actor->id
        );

        $this->service->endPeriod(
            (string) $school->id,
            (string) $period->id,
            (string) $actor->id,
            'Period closure'
        );

        $historicalRow = DB::table('teacher_duty_assignments')
            ->where('id', $historical->id)
            ->first();

        $this->assertNotNull($historicalRow);
        $this->assertFalse((bool) $historicalRow->active);
        $this->assertSame($oldEndedBy, (string) $historicalRow->ended_by);
        $this->assertSame(
            $oldEndedAt,
            (string) $historicalRow->ended_at
        );
        $this->assertSame($oldReason, $historicalRow->end_reason);
    }

    public function test_ended_period_cannot_be_ended_again(): void
    {
        $school = $this->school();
        $actor = $this->user($school);
        $period = $this->periodFixture($school, $actor);

        $this->service->endPeriod(
            (string) $school->id,
            (string) $period->id,
            (string) $actor->id
        );

        $this->expectValidationField(
            'period',
            fn () => $this->service->endPeriod(
                (string) $school->id,
                (string) $period->id,
                (string) $actor->id
            )
        );
    }

    public function test_current_and_history_reads_are_tenant_scoped_and_history_survives_teacher_retirement(): void
    {
        $schoolA = $this->school();
        $actorA = $this->user($schoolA);
        $teacherA = $this->teacher($schoolA);
        $periodA = $this->periodFixture($schoolA, $actorA);

        $assignment = $this->service->assignTeacher(
            (string) $schoolA->id,
            (string) $periodA->id,
            (string) $teacherA->id,
            (string) $actorA->id
        );

        $this->service->endAssignment(
            (string) $schoolA->id,
            (string) $assignment->id,
            (string) $actorA->id
        );

        DB::table('teachers')
            ->where('id', $teacherA->id)
            ->update([
                'active' => false,
                'is_deleted' => true,
            ]);

        DB::table('users')
            ->where('id', $teacherA->user_id)
            ->update([
                'active' => false,
                'is_deleted' => true,
            ]);

        $history = $this->service->assignmentHistoryForPeriod(
            (string) $schoolA->id,
            (string) $periodA->id
        );

        $this->assertCount(1, $history);
        $this->assertSame(
            (string) $assignment->id,
            (string) $history->first()->id
        );

        $current = $this->service->currentAssignmentsForPeriod(
            (string) $schoolA->id,
            (string) $periodA->id
        );

        $this->assertCount(0, $current);

        $schoolB = $this->school();

        $this->expectModelNotFound(
            fn () => $this->service->period(
                (string) $schoolB->id,
                (string) $periodA->id
            )
        );

        $this->expectModelNotFound(
            fn () => $this->service->assignment(
                (string) $schoolB->id,
                (string) $assignment->id
            )
        );

        $this->expectModelNotFound(
            fn () => $this->service->currentAssignmentsForPeriod(
                (string) $schoolB->id,
                (string) $periodA->id
            )
        );
    }

    public function test_period_listing_separates_current_from_full_history(): void
    {
        $school = $this->school();
        $actor = $this->user($school);

        $ended = $this->service->createPeriod(
            (string) $school->id,
            '2026-09-01',
            '2026-09-05',
            null,
            (string) $actor->id
        );

        $current = $this->service->createPeriod(
            (string) $school->id,
            '2026-09-07',
            '2026-09-11',
            null,
            (string) $actor->id
        );

        $this->service->endPeriod(
            (string) $school->id,
            (string) $ended->id,
            (string) $actor->id
        );

        $currentPeriods = $this->service->currentPeriods(
            (string) $school->id
        );

        $history = $this->service->periodHistory(
            (string) $school->id
        );

        $this->assertCount(1, $currentPeriods);
        $this->assertSame(
            (string) $current->id,
            (string) $currentPeriods->first()->id
        );

        $this->assertCount(2, $history);

        $ids = $history
            ->pluck('id')
            ->map(fn ($id): string => (string) $id)
            ->all();

        $this->assertContains((string) $ended->id, $ids);
        $this->assertContains((string) $current->id, $ids);
    }

    public function test_generic_delete_is_forbidden_for_periods_and_assignments(): void
    {
        $school = $this->school();
        $actor = $this->user($school);
        $teacher = $this->teacher($school);
        $period = $this->periodFixture($school, $actor);

        $assignment = $this->service->assignTeacher(
            (string) $school->id,
            (string) $period->id,
            (string) $teacher->id,
            (string) $actor->id
        );

        foreach ([$period->refresh(), $assignment->refresh()] as $model) {
            try {
                $model->delete();

                $this->fail(
                    'Teacher duty lifecycle history was deletable.'
                );
            } catch (LogicException $exception) {
                $this->assertStringContainsString(
                    'cannot be deleted',
                    $exception->getMessage()
                );
            }
        }

        $this->assertDatabaseHas('teacher_duty_periods', [
            'id' => $period->id,
        ]);

        $this->assertDatabaseHas('teacher_duty_assignments', [
            'id' => $assignment->id,
        ]);
    }

    public function test_database_rejects_nonexistent_academic_week_reference(): void
    {
        $school = $this->school();
        $actor = $this->user($school);

        try {
            DB::transaction(function () use ($school, $actor): void {
                DB::table('teacher_duty_periods')->insert([
                    'id' => (string) Str::uuid(),
                    'school_id' => $school->id,
                    'academic_week_id' => (string) Str::uuid(),
                    'start_date' => '2026-09-07',
                    'end_date' => '2026-09-11',
                    'active' => true,
                    'created_by' => $actor->id,
                    'ended_by' => null,
                    'ended_at' => null,
                    'end_reason' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            });

            $this->fail(
                'PostgreSQL accepted a nonexistent academic week reference.'
            );
        } catch (QueryException $exception) {
            $this->assertSame(
                '23503',
                $exception->errorInfo[0] ?? null
            );

            $this->assertStringContainsString(
                'teacher_duty_periods_school_academic_week_foreign',
                $exception->getMessage()
            );
        }

        $this->assertSame(
            0,
            DB::table('teacher_duty_periods')
                ->where('school_id', $school->id)
                ->count()
        );
    }

    public function test_database_accepts_valid_terminal_period_state(): void
    {
        $school = $this->school();
        $actor = $this->user($school);

        $period = $this->service->createPeriod(
            (string) $school->id,
            '2026-09-07',
            '2026-09-11',
            null,
            (string) $actor->id
        );

        $endedAt = now();

        $updated = DB::table('teacher_duty_periods')
            ->where('id', $period->id)
            ->update([
                'active' => false,
                'ended_by' => $actor->id,
                'ended_at' => $endedAt,
                'end_reason' => 'DB terminal contract verification',
                'updated_at' => now(),
            ]);

        $this->assertSame(1, $updated);

        $row = DB::table('teacher_duty_periods')
            ->where('id', $period->id)
            ->first();

        $this->assertNotNull($row);
        $this->assertFalse((bool) $row->active);
        $this->assertSame(
            (string) $actor->id,
            (string) $row->ended_by
        );
        $this->assertNotNull($row->ended_at);
        $this->assertSame(
            'DB terminal contract verification',
            $row->end_reason
        );
    }

    private function periodFixture(
        School $school,
        User $actor
    ): TeacherDutyPeriod {
        return $this->service->createPeriod(
            (string) $school->id,
            '2026-09-07',
            '2026-09-11',
            null,
            (string) $actor->id
        );
    }

    private function teacher(School $school): object
    {
        $user = $this->user($school);

        return TeacherBuilder::create(
            $school,
            $user
        );
    }

    private function academicWeek(
        School $school,
        array $attributes = []
    ): object {
        $academicYearId = (string) Str::uuid();
        $termId = (string) Str::uuid();
        $weekId = (string) Str::uuid();

        DB::table('academic_years')->insert([
            'id' => $academicYearId,
            'school_id' => $school->id,
            'year_name' => 'Duty '.Str::upper(Str::random(8)),
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'active' => true,
            'created_at' => now(),
        ]);

        DB::table('terms')->insert([
            'id' => $termId,
            'school_id' => $school->id,
            'academic_year_id' => $academicYearId,
            'term_name' => 'Duty '.Str::upper(Str::random(8)),
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'active' => true,
            'created_at' => now(),
        ]);

        $record = array_merge([
            'id' => $weekId,
            'school_id' => $school->id,
            'academic_year_id' => $academicYearId,
            'term_id' => $termId,
            'week_number' => random_int(100, 1000000),
            'start_date' => '2026-09-07',
            'end_date' => '2026-09-11',
            'active' => true,
            'created_at' => now(),
        ], $attributes);

        DB::table('academic_weeks')->insert($record);

        return (object) $record;
    }

    private function school(): School
    {
        return School::query()->create([
            'id' => (string) Str::uuid(),
            'school_name' => 'Teacher Duty '.Str::upper(
                Str::random(8)
            ),
            'school_code' => 'TD-'.Str::upper(
                Str::random(8)
            ),
            'short_name' => 'TD',
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
            'role_name' => 'Teacher Duty Test '.Str::upper(
                Str::random(8)
            ),
            'description' => 'Teacher duty service test role',
            'active' => true,
        ]);

        return User::query()->create([
            'id' => (string) Str::uuid(),
            'school_id' => $school->id,
            'role_id' => $role->id,
            'first_name' => 'Teacher',
            'last_name' => 'Duty',
            'username' => 'teacher_duty_'.Str::lower(
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

    private function expectValidationField(
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

    private function expectModelNotFound(
        callable $callback
    ): void {
        try {
            $callback();

            $this->fail(
                'Expected tenant-scoped lookup to fail closed.'
            );
        } catch (ModelNotFoundException) {
            $this->assertTrue(true);
        }
    }
}
