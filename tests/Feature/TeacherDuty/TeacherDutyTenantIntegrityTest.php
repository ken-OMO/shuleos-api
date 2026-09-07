<?php

declare(strict_types=1);

namespace Tests\Feature\TeacherDuty;

use App\Models\Role;
use App\Models\School;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\Database\TeacherBuilder;
use Tests\TestCase;

class TeacherDutyTenantIntegrityTest extends TestCase
{
    use DatabaseTransactions;

    public function test_database_accepts_valid_same_school_tenant_relationships(): void
    {
        $school = $this->school();
        $actor = $this->user($school);
        $teacher = $this->teacher($school);
        $week = $this->academicWeek($school);

        $periodId = $this->insertPeriod(
            $school,
            $actor,
            (string) $week->id
        );

        $assignmentId = $this->insertAssignment(
            $school,
            $periodId,
            (string) $teacher->id,
            $actor
        );

        $this->assertDatabaseHas('teacher_duty_periods', [
            'id' => $periodId,
            'school_id' => $school->id,
            'academic_week_id' => $week->id,
        ]);

        $this->assertDatabaseHas('teacher_duty_assignments', [
            'id' => $assignmentId,
            'school_id' => $school->id,
            'duty_period_id' => $periodId,
            'teacher_id' => $teacher->id,
        ]);
    }

    public function test_database_rejects_period_academic_week_tenant_mismatch(): void
    {
        $schoolA = $this->school();
        $schoolB = $this->school();

        $actorA = $this->user($schoolA);
        $weekB = $this->academicWeek($schoolB);
        $periodId = (string) Str::uuid();

        $this->expectForeignKeyViolation(
            'teacher_duty_period_week_tenant_mismatch',
            'teacher_duty_periods_school_academic_week_foreign',
            function () use (
                $periodId,
                $schoolA,
                $actorA,
                $weekB
            ): void {
                DB::table('teacher_duty_periods')->insert([
                    'id' => $periodId,
                    'school_id' => $schoolA->id,
                    'academic_week_id' => $weekB->id,
                    'start_date' => '2026-09-07',
                    'end_date' => '2026-09-11',
                    'active' => true,
                    'created_by' => $actorA->id,
                    'ended_by' => null,
                    'ended_at' => null,
                    'end_reason' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        );

        $this->assertDatabaseMissing('teacher_duty_periods', [
            'id' => $periodId,
        ]);
    }

    public function test_database_rejects_assignment_duty_period_tenant_mismatch(): void
    {
        $schoolA = $this->school();
        $schoolB = $this->school();

        $actorA = $this->user($schoolA);
        $actorB = $this->user($schoolB);

        $teacherA = $this->teacher($schoolA);

        $periodB = $this->insertPeriod(
            $schoolB,
            $actorB
        );

        $assignmentId = (string) Str::uuid();

        $this->expectForeignKeyViolation(
            'teacher_duty_assignment_period_tenant_mismatch',
            'teacher_duty_assignments_school_period_foreign',
            function () use (
                $assignmentId,
                $schoolA,
                $periodB,
                $teacherA,
                $actorA
            ): void {
                DB::table('teacher_duty_assignments')->insert([
                    'id' => $assignmentId,
                    'school_id' => $schoolA->id,
                    'duty_period_id' => $periodB,
                    'teacher_id' => $teacherA->id,
                    'active' => true,
                    'assigned_by' => $actorA->id,
                    'ended_by' => null,
                    'ended_at' => null,
                    'end_reason' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        );

        $this->assertDatabaseMissing('teacher_duty_assignments', [
            'id' => $assignmentId,
        ]);
    }

    public function test_database_rejects_assignment_teacher_tenant_mismatch(): void
    {
        $schoolA = $this->school();
        $schoolB = $this->school();

        $actorA = $this->user($schoolA);
        $teacherB = $this->teacher($schoolB);

        $periodA = $this->insertPeriod(
            $schoolA,
            $actorA
        );

        $assignmentId = (string) Str::uuid();

        $this->expectForeignKeyViolation(
            'teacher_duty_assignment_teacher_tenant_mismatch',
            'teacher_duty_assignments_school_teacher_foreign',
            function () use (
                $assignmentId,
                $schoolA,
                $periodA,
                $teacherB,
                $actorA
            ): void {
                DB::table('teacher_duty_assignments')->insert([
                    'id' => $assignmentId,
                    'school_id' => $schoolA->id,
                    'duty_period_id' => $periodA,
                    'teacher_id' => $teacherB->id,
                    'active' => true,
                    'assigned_by' => $actorA->id,
                    'ended_by' => null,
                    'ended_at' => null,
                    'end_reason' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        );

        $this->assertDatabaseMissing('teacher_duty_assignments', [
            'id' => $assignmentId,
        ]);
    }

    private function expectForeignKeyViolation(
        string $savepoint,
        string $constraint,
        callable $callback
    ): void {
        DB::statement("SAVEPOINT {$savepoint}");

        try {
            $callback();

            DB::statement("RELEASE SAVEPOINT {$savepoint}");

            $this->fail(
                "PostgreSQL accepted tenant mismatch protected by {$constraint}."
            );
        } catch (QueryException $exception) {
            DB::statement("ROLLBACK TO SAVEPOINT {$savepoint}");
            DB::statement("RELEASE SAVEPOINT {$savepoint}");

            $this->assertSame(
                '23503',
                (string) ($exception->errorInfo[0] ?? '')
            );

            $this->assertStringContainsString(
                $constraint,
                $exception->getMessage()
            );
        }
    }

    private function insertPeriod(
        School $school,
        User $actor,
        ?string $academicWeekId = null
    ): string {
        $id = (string) Str::uuid();

        DB::table('teacher_duty_periods')->insert([
            'id' => $id,
            'school_id' => $school->id,
            'academic_week_id' => $academicWeekId,
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

    private function insertAssignment(
        School $school,
        string $periodId,
        string $teacherId,
        User $actor
    ): string {
        $id = (string) Str::uuid();

        DB::table('teacher_duty_assignments')->insert([
            'id' => $id,
            'school_id' => $school->id,
            'duty_period_id' => $periodId,
            'teacher_id' => $teacherId,
            'active' => true,
            'assigned_by' => $actor->id,
            'ended_by' => null,
            'ended_at' => null,
            'end_reason' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function teacher(School $school): object
    {
        $user = $this->user($school);

        return TeacherBuilder::create(
            $school,
            $user
        );
    }

    private function academicWeek(School $school): object
    {
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

        $record = [
            'id' => $weekId,
            'school_id' => $school->id,
            'academic_year_id' => $academicYearId,
            'term_id' => $termId,
            'week_number' => random_int(100, 1000000),
            'start_date' => '2026-09-07',
            'end_date' => '2026-09-11',
            'active' => true,
            'created_at' => now(),
        ];

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
            'description' => 'Teacher duty tenant integrity test role',
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
}
