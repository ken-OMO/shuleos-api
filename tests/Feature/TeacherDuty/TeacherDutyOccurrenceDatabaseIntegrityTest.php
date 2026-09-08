<?php

declare(strict_types=1);

namespace Tests\Feature\TeacherDuty;

use App\Models\Role;
use App\Models\School;
use App\Models\TeacherDutyOccurrence;
use App\Models\TeacherDutyOccurrenceCategory;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class TeacherDutyOccurrenceDatabaseIntegrityTest extends TestCase
{
    use DatabaseTransactions;

    public function test_database_accepts_same_school_custom_category(): void
    {
        $school = $this->school();
        $creator = $this->user($school);

        $id = $this->insertCustomCategory(
            $school,
            $creator,
            'pastoral_support'
        );

        $this->assertDatabaseHas(
            'teacher_duty_occurrence_categories',
            [
                'id' => $id,
                'school_id' => $school->id,
                'code' => 'pastoral_support',
                'is_canonical' => false,
                'created_by' => $creator->id,
                'active' => true,
            ]
        );
    }

    public function test_database_rejects_duplicate_category_code_within_school(): void
    {
        $school = $this->school();
        $creator = $this->user($school);

        $this->insertCustomCategory(
            $school,
            $creator,
            'pastoral_support'
        );

        $this->expectDatabaseViolation(
            'teacher_duty_occurrence_categories_school_code_unique',
            function () use ($school, $creator): void {
                $this->insertCustomCategory(
                    $school,
                    $creator,
                    'pastoral_support'
                );
            }
        );
    }

    public function test_database_allows_same_category_code_across_schools(): void
    {
        $schoolA = $this->school();
        $schoolB = $this->school();

        $creatorA = $this->user($schoolA);
        $creatorB = $this->user($schoolB);

        $this->insertCustomCategory(
            $schoolA,
            $creatorA,
            'pastoral_support'
        );

        $this->insertCustomCategory(
            $schoolB,
            $creatorB,
            'pastoral_support'
        );

        $this->assertSame(
            2,
            DB::table('teacher_duty_occurrence_categories')
                ->where('code', 'pastoral_support')
                ->count()
        );
    }

    public function test_database_rejects_custom_category_creator_tenant_mismatch(): void
    {
        $schoolA = $this->school();
        $schoolB = $this->school();

        $creatorB = $this->user($schoolB);

        $this->expectDatabaseViolation(
            'teacher_duty_occurrence_categories_school_created_by_foreign',
            function () use ($schoolA, $creatorB): void {
                $this->insertCustomCategory(
                    $schoolA,
                    $creatorB,
                    'pastoral_support'
                );
            }
        );
    }

    public function test_database_rejects_reserved_code_as_noncanonical(): void
    {
        $school = $this->school();
        $creator = $this->user($school);

        $this->expectDatabaseViolation(
            'teacher_duty_occurrence_categories_canonical_identity_check',
            function () use ($school, $creator): void {
                $this->insertCustomCategory(
                    $school,
                    $creator,
                    'discipline'
                );
            }
        );
    }

    public function test_database_rejects_nonreserved_code_as_canonical(): void
    {
        $school = $this->school();

        $this->expectDatabaseViolation(
            'teacher_duty_occurrence_categories_canonical_identity_check',
            function () use ($school): void {
                $this->insertCanonicalCategory(
                    $school,
                    'pastoral_support'
                );
            }
        );
    }

    public function test_database_rejects_custom_category_without_creator(): void
    {
        $school = $this->school();

        $this->expectDatabaseViolation(
            'teacher_duty_occurrence_categories_provenance_check',
            function () use ($school): void {
                $this->insertCategory([
                    'school_id' => $school->id,
                    'code' => 'pastoral_support',
                    'name' => 'Pastoral Support',
                    'is_canonical' => false,
                    'created_by' => null,
                ]);
            }
        );
    }

    public function test_database_rejects_canonical_category_with_creator(): void
    {
        $school = $this->school();
        $creator = $this->user($school);

        $this->expectDatabaseViolation(
            'teacher_duty_occurrence_categories_provenance_check',
            function () use ($school, $creator): void {
                $this->insertCategory([
                    'school_id' => $school->id,
                    'code' => 'discipline',
                    'name' => 'Discipline',
                    'is_canonical' => true,
                    'created_by' => $creator->id,
                ]);
            }
        );
    }

    public function test_database_rejects_active_category_with_deactivation_evidence(): void
    {
        $school = $this->school();
        $creator = $this->user($school);

        $this->expectDatabaseViolation(
            'teacher_duty_occurrence_categories_lifecycle_check',
            function () use ($school, $creator): void {
                $this->insertCategory([
                    'school_id' => $school->id,
                    'code' => 'pastoral_support',
                    'name' => 'Pastoral Support',
                    'is_canonical' => false,
                    'created_by' => $creator->id,
                    'active' => true,
                    'deactivated_by' => $creator->id,
                    'deactivated_at' => now(),
                ]);
            }
        );
    }

    public function test_database_rejects_inactive_category_without_complete_deactivation_evidence(): void
    {
        $school = $this->school();
        $creator = $this->user($school);

        $this->expectDatabaseViolation(
            'teacher_duty_occurrence_categories_lifecycle_check',
            function () use ($school, $creator): void {
                $this->insertCategory([
                    'school_id' => $school->id,
                    'code' => 'pastoral_support',
                    'name' => 'Pastoral Support',
                    'is_canonical' => false,
                    'created_by' => $creator->id,
                    'active' => false,
                    'deactivated_by' => null,
                    'deactivated_at' => null,
                ]);
            }
        );
    }

    public function test_database_rejects_category_deactivator_tenant_mismatch(): void
    {
        $schoolA = $this->school();
        $schoolB = $this->school();

        $creatorA = $this->user($schoolA);
        $deactivatorB = $this->user($schoolB);

        $this->expectDatabaseViolation(
            'teacher_duty_occurrence_categories_school_deactivated_by_foreig',
            function () use (
                $schoolA,
                $creatorA,
                $deactivatorB
            ): void {
                $this->insertCategory([
                    'school_id' => $schoolA->id,
                    'code' => 'pastoral_support',
                    'name' => 'Pastoral Support',
                    'is_canonical' => false,
                    'created_by' => $creatorA->id,
                    'active' => false,
                    'deactivated_by' => $deactivatorB->id,
                    'deactivated_at' => now(),
                ]);
            }
        );
    }

    public function test_database_accepts_valid_terminal_category_state(): void
    {
        $school = $this->school();
        $creator = $this->user($school);
        $deactivator = $this->user($school);

        $id = $this->insertCategory([
            'school_id' => $school->id,
            'code' => 'pastoral_support',
            'name' => 'Pastoral Support',
            'is_canonical' => false,
            'created_by' => $creator->id,
            'active' => false,
            'deactivated_by' => $deactivator->id,
            'deactivated_at' => now(),
        ]);

        $this->assertDatabaseHas(
            'teacher_duty_occurrence_categories',
            [
                'id' => $id,
                'school_id' => $school->id,
                'active' => false,
                'created_by' => $creator->id,
                'deactivated_by' => $deactivator->id,
            ]
        );
    }

    public function test_database_accepts_valid_same_school_occurrence(): void
    {
        $school = $this->school();
        $actor = $this->user($school);

        $periodId = $this->insertPeriod(
            $school,
            $actor
        );

        $categoryId = $this->insertCustomCategory(
            $school,
            $actor,
            'pastoral_support'
        );

        $occurrenceId = $this->insertOccurrence(
            $school,
            $periodId,
            $categoryId,
            $actor
        );

        $this->assertDatabaseHas(
            'teacher_duty_occurrences',
            [
                'id' => $occurrenceId,
                'school_id' => $school->id,
                'duty_period_id' => $periodId,
                'occurrence_category_id' => $categoryId,
                'occurrence_date' => '2026-09-09',
                'description' => 'Learner support incident recorded.',
                'recorded_by' => $actor->id,
            ]
        );
    }

    public function test_database_rejects_occurrence_period_tenant_mismatch(): void
    {
        $schoolA = $this->school();
        $schoolB = $this->school();

        $actorA = $this->user($schoolA);
        $actorB = $this->user($schoolB);

        $periodB = $this->insertPeriod(
            $schoolB,
            $actorB
        );

        $categoryA = $this->insertCustomCategory(
            $schoolA,
            $actorA,
            'pastoral_support'
        );

        $this->expectDatabaseViolation(
            'teacher_duty_occurrences_school_period_foreign',
            function () use (
                $schoolA,
                $periodB,
                $categoryA,
                $actorA
            ): void {
                $this->insertOccurrence(
                    $schoolA,
                    $periodB,
                    $categoryA,
                    $actorA
                );
            }
        );
    }

    public function test_database_rejects_occurrence_category_tenant_mismatch(): void
    {
        $schoolA = $this->school();
        $schoolB = $this->school();

        $actorA = $this->user($schoolA);
        $actorB = $this->user($schoolB);

        $periodA = $this->insertPeriod(
            $schoolA,
            $actorA
        );

        $categoryB = $this->insertCustomCategory(
            $schoolB,
            $actorB,
            'pastoral_support'
        );

        $this->expectDatabaseViolation(
            'teacher_duty_occurrences_school_category_foreign',
            function () use (
                $schoolA,
                $periodA,
                $categoryB,
                $actorA
            ): void {
                $this->insertOccurrence(
                    $schoolA,
                    $periodA,
                    $categoryB,
                    $actorA
                );
            }
        );
    }

    public function test_database_rejects_occurrence_recorder_tenant_mismatch(): void
    {
        $schoolA = $this->school();
        $schoolB = $this->school();

        $actorA = $this->user($schoolA);
        $actorB = $this->user($schoolB);

        $periodA = $this->insertPeriod(
            $schoolA,
            $actorA
        );

        $categoryA = $this->insertCustomCategory(
            $schoolA,
            $actorA,
            'pastoral_support'
        );

        $this->expectDatabaseViolation(
            'teacher_duty_occurrences_school_recorded_by_foreign',
            function () use (
                $schoolA,
                $periodA,
                $categoryA,
                $actorB
            ): void {
                $this->insertOccurrence(
                    $schoolA,
                    $periodA,
                    $categoryA,
                    $actorB
                );
            }
        );
    }

    public function test_database_allows_multiple_occurrences_for_same_period_category_and_date(): void
    {
        $school = $this->school();
        $actor = $this->user($school);

        $periodId = $this->insertPeriod(
            $school,
            $actor
        );

        $categoryId = $this->insertCustomCategory(
            $school,
            $actor,
            'pastoral_support'
        );

        $firstId = $this->insertOccurrence(
            $school,
            $periodId,
            $categoryId,
            $actor,
            '08:15:00',
            'First learner support incident.'
        );

        $secondId = $this->insertOccurrence(
            $school,
            $periodId,
            $categoryId,
            $actor,
            '11:30:00',
            'Second learner support incident.'
        );

        $this->assertNotSame(
            $firstId,
            $secondId
        );

        $this->assertSame(
            2,
            DB::table('teacher_duty_occurrences')
                ->where('school_id', $school->id)
                ->where('duty_period_id', $periodId)
                ->where(
                    'occurrence_category_id',
                    $categoryId
                )
                ->whereDate(
                    'occurrence_date',
                    '2026-09-09'
                )
                ->count()
        );
    }

    public function test_occurrence_history_survives_category_deactivation_and_period_closure(): void
    {
        $school = $this->school();
        $actor = $this->user($school);

        $periodId = $this->insertPeriod(
            $school,
            $actor
        );

        $categoryId = $this->insertCustomCategory(
            $school,
            $actor,
            'pastoral_support'
        );

        $occurrenceId = $this->insertOccurrence(
            $school,
            $periodId,
            $categoryId,
            $actor
        );

        DB::table(
            'teacher_duty_occurrence_categories'
        )
            ->where('id', $categoryId)
            ->update([
                'active' => false,
                'deactivated_by' => $actor->id,
                'deactivated_at' => now(),
                'updated_at' => now(),
            ]);

        DB::table('teacher_duty_periods')
            ->where('id', $periodId)
            ->update([
                'active' => false,
                'ended_by' => $actor->id,
                'ended_at' => now(),
                'end_reason' => 'Duty period completed.',
                'updated_at' => now(),
            ]);

        $this->assertDatabaseHas(
            'teacher_duty_occurrences',
            [
                'id' => $occurrenceId,
                'school_id' => $school->id,
                'duty_period_id' => $periodId,
                'occurrence_category_id' => $categoryId,
                'recorded_by' => $actor->id,
            ]
        );

        $this->assertSame(
            1,
            DB::table('teacher_duty_occurrences')
                ->where('id', $occurrenceId)
                ->count()
        );
    }

    public function test_occurrence_model_prohibits_generic_deletion(): void
    {
        $school = $this->school();
        $actor = $this->user($school);

        $periodId = $this->insertPeriod(
            $school,
            $actor
        );

        $categoryId = $this->insertCustomCategory(
            $school,
            $actor,
            'pastoral_support'
        );

        $occurrenceId = $this->insertOccurrence(
            $school,
            $periodId,
            $categoryId,
            $actor
        );

        $occurrence = TeacherDutyOccurrence::query()
            ->withoutGlobalScopes()
            ->findOrFail($occurrenceId);

        try {
            $occurrence->delete();

            $this->fail(
                'Generic Teacher Duty occurrence deletion was allowed.'
            );
        } catch (\LogicException $exception) {
            $this->assertSame(
                'Teacher duty occurrences cannot be deleted.',
                $exception->getMessage()
            );
        }

        $this->assertDatabaseHas(
            'teacher_duty_occurrences',
            [
                'id' => $occurrenceId,
            ]
        );
    }

    public function test_occurrence_category_model_prohibits_hard_deletion(): void
    {
        $school = $this->school();
        $actor = $this->user($school);

        $categoryId = $this->insertCustomCategory(
            $school,
            $actor,
            'pastoral_support'
        );

        $category = TeacherDutyOccurrenceCategory::query()
            ->withoutGlobalScopes()
            ->findOrFail($categoryId);

        try {
            $category->delete();

            $this->fail(
                'Teacher Duty occurrence category deletion was allowed.'
            );
        } catch (\LogicException $exception) {
            $this->assertSame(
                'Teacher duty occurrence categories cannot be deleted.',
                $exception->getMessage()
            );
        }

        $this->assertDatabaseHas(
            'teacher_duty_occurrence_categories',
            [
                'id' => $categoryId,
            ]
        );
    }

    private function insertCanonicalCategory(
        School $school,
        string $code
    ): string {
        return $this->insertCategory([
            'school_id' => $school->id,
            'code' => $code,
            'name' => 'Canonical Test Category',
            'is_canonical' => true,
            'created_by' => null,
        ]);
    }

    private function insertCategory(array $attributes): string
    {
        $id = (string) Str::uuid();

        DB::table(
            'teacher_duty_occurrence_categories'
        )->insert(array_merge([
            'id' => $id,
            'description' => null,
            'display_order' => 90,
            'active' => true,
            'deactivated_by' => null,
            'deactivated_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ], $attributes));

        return $id;
    }

    private function insertCustomCategory(
        School $school,
        User $creator,
        string $code
    ): string {
        $id = (string) Str::uuid();

        DB::table(
            'teacher_duty_occurrence_categories'
        )->insert([
            'id' => $id,
            'school_id' => $school->id,
            'code' => $code,
            'name' => 'Pastoral Support',
            'description' => null,
            'is_canonical' => false,
            'display_order' => 90,
            'active' => true,
            'created_by' => $creator->id,
            'deactivated_by' => null,
            'deactivated_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function expectDatabaseViolation(
        string $constraint,
        callable $callback
    ): void {
        try {
            DB::transaction(
                function () use ($callback): void {
                    $callback();
                }
            );

            $this->fail(
                "PostgreSQL accepted data violating {$constraint}."
            );
        } catch (QueryException $exception) {
            $this->assertStringContainsString(
                $constraint,
                $exception->getMessage()
            );
        }
    }

    private function insertPeriod(
        School $school,
        User $actor
    ): string {
        $id = (string) Str::uuid();

        DB::table('teacher_duty_periods')->insert([
            'id' => $id,
            'school_id' => $school->id,
            'academic_week_id' => null,
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

        return $id;
    }

    private function insertOccurrence(
        School $school,
        string $periodId,
        string $categoryId,
        User $recordedBy,
        ?string $occurrenceTime = '10:15:00',
        string $description = 'Learner support incident recorded.'
    ): string {
        $id = (string) Str::uuid();

        DB::table('teacher_duty_occurrences')->insert([
            'id' => $id,
            'school_id' => $school->id,
            'duty_period_id' => $periodId,
            'occurrence_category_id' => $categoryId,
            'occurrence_date' => '2026-09-09',
            'occurrence_time' => $occurrenceTime,
            'description' => $description,
            'recorded_by' => $recordedBy->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function school(): School
    {
        return School::query()->create([
            'id' => (string) Str::uuid(),
            'school_name' => 'Occurrence '.Str::upper(
                Str::random(8)
            ),
            'school_code' => 'OCC-'.Str::upper(
                Str::random(8)
            ),
            'short_name' => 'OCC',
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
            'active' => true,
        ]);
    }

    private function user(School $school): User
    {
        $role = Role::query()->create([
            'id' => (string) Str::uuid(),
            'role_name' => 'Occurrence Test '.Str::upper(
                Str::random(8)
            ),
            'active' => true,
        ]);

        return User::query()->create([
            'id' => (string) Str::uuid(),
            'school_id' => $school->id,
            'role_id' => $role->id,
            'first_name' => 'Teacher',
            'last_name' => 'Duty',
            'username' => 'occurrence_'.Str::lower(
                Str::random(10)
            ),
            'email' => Str::lower(
                Str::random(10)
            ).'@example.test',
            'password_hash' => 'test-password-hash',
            'active' => true,
            'is_deleted' => false,
        ]);
    }
}
