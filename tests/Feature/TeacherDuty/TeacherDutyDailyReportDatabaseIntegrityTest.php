<?php

declare(strict_types=1);

namespace Tests\Feature\TeacherDuty;

use App\Models\Role;
use App\Models\School;
use App\Models\TeacherDutyDailyReport;
use App\Models\TeacherDutyDailyReportHistory;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class TeacherDutyDailyReportDatabaseIntegrityTest extends TestCase
{
    use DatabaseTransactions;

    public function test_database_rejects_negative_teacher_duty_grace_minutes(): void
    {
        $school = $this->school();

        DB::table('school_settings')->insert([
            'school_id' => $school->id,
        ]);

        $this->expectDatabaseViolation(
            'school_settings_teacher_duty_grace_nonnegative_check',
            function () use ($school): void {
                DB::table('school_settings')
                    ->where('school_id', $school->id)
                    ->update([
                        'teacher_duty_report_grace_minutes' => -1,
                    ]);
            }
        );
    }

    public function test_database_accepts_valid_draft_report(): void
    {
        $school = $this->school();
        $actor = $this->user($school);
        $periodId = $this->period($school, $actor);

        $reportId = $this->insertReport(
            $school,
            $periodId,
            $actor
        );

        $this->assertDatabaseHas(
            'teacher_duty_daily_reports',
            [
                'id' => $reportId,
                'school_id' => $school->id,
                'duty_period_id' => $periodId,
                'report_date' => '2026-09-09',
                'status' => 'draft',
                'created_by' => $actor->id,
                'submitted_by' => null,
                'submitted_at' => null,
            ]
        );
    }

    public function test_database_rejects_duplicate_report_identity(): void
    {
        $school = $this->school();
        $actor = $this->user($school);
        $periodId = $this->period($school, $actor);

        $this->insertReport(
            $school,
            $periodId,
            $actor
        );

        $this->expectDatabaseViolation(
            'td_daily_reports_school_period_date_unique',
            function () use ($school, $periodId, $actor): void {
                $this->insertReport(
                    $school,
                    $periodId,
                    $actor
                );
            }
        );
    }

    public function test_database_rejects_report_period_tenant_mismatch(): void
    {
        $schoolA = $this->school();
        $schoolB = $this->school();

        $actorA = $this->user($schoolA);
        $actorB = $this->user($schoolB);

        $periodB = $this->period(
            $schoolB,
            $actorB
        );

        $this->expectDatabaseViolation(
            'td_daily_reports_school_period_foreign',
            function () use (
                $schoolA,
                $periodB,
                $actorA
            ): void {
                $this->insertReport(
                    $schoolA,
                    $periodB,
                    $actorA
                );
            }
        );
    }

    public function test_database_rejects_report_creator_tenant_mismatch(): void
    {
        $schoolA = $this->school();
        $schoolB = $this->school();

        $actorA = $this->user($schoolA);
        $actorB = $this->user($schoolB);

        $periodA = $this->period(
            $schoolA,
            $actorA
        );

        $this->expectDatabaseViolation(
            'td_daily_reports_school_created_by_foreign',
            function () use (
                $schoolA,
                $periodA,
                $actorB
            ): void {
                $this->insertReport(
                    $schoolA,
                    $periodA,
                    $actorB
                );
            }
        );
    }

    public function test_database_rejects_invalid_persisted_report_status(): void
    {
        $school = $this->school();
        $actor = $this->user($school);
        $periodId = $this->period($school, $actor);

        $this->expectDatabaseRejection(
            function () use (
                $school,
                $periodId,
                $actor
            ): void {
                $this->insertReport(
                    $school,
                    $periodId,
                    $actor,
                    'overdue'
                );
            }
        );
    }

    public function test_database_rejects_draft_with_submission_evidence(): void
    {
        $school = $this->school();
        $actor = $this->user($school);
        $periodId = $this->period($school, $actor);

        $this->expectDatabaseViolation(
            'td_daily_reports_submission_evidence_check',
            function () use (
                $school,
                $periodId,
                $actor
            ): void {
                $this->insertReport(
                    $school,
                    $periodId,
                    $actor,
                    'draft',
                    $actor,
                    now()
                );
            }
        );
    }

    public function test_database_rejects_submitted_without_complete_submission_evidence(): void
    {
        $school = $this->school();
        $actor = $this->user($school);
        $periodId = $this->period($school, $actor);

        $this->expectDatabaseViolation(
            'td_daily_reports_submission_evidence_check',
            function () use (
                $school,
                $periodId,
                $actor
            ): void {
                $this->insertReport(
                    $school,
                    $periodId,
                    $actor,
                    'submitted',
                    $actor,
                    null
                );
            }
        );
    }

    public function test_database_rejects_submitter_tenant_mismatch(): void
    {
        $schoolA = $this->school();
        $schoolB = $this->school();

        $creatorA = $this->user($schoolA);
        $submitterB = $this->user($schoolB);

        $periodA = $this->period(
            $schoolA,
            $creatorA
        );

        $this->expectDatabaseViolation(
            'td_daily_reports_school_submitted_by_foreign',
            function () use (
                $schoolA,
                $periodA,
                $creatorA,
                $submitterB
            ): void {
                $this->insertReport(
                    $schoolA,
                    $periodA,
                    $creatorA,
                    'submitted',
                    $submitterB,
                    now()
                );
            }
        );
    }

    public function test_database_accepts_valid_submitted_report(): void
    {
        $school = $this->school();
        $actor = $this->user($school);
        $periodId = $this->period($school, $actor);

        $submittedAt = now();

        $reportId = $this->insertReport(
            $school,
            $periodId,
            $actor,
            'submitted',
            $actor,
            $submittedAt
        );

        $this->assertDatabaseHas(
            'teacher_duty_daily_reports',
            [
                'id' => $reportId,
                'school_id' => $school->id,
                'status' => 'submitted',
                'submitted_by' => $actor->id,
            ]
        );

        $this->assertNotNull(
            DB::table('teacher_duty_daily_reports')
                ->where('id', $reportId)
                ->value('submitted_at')
        );
    }

    public function test_database_accepts_valid_same_school_history(): void
    {
        $school = $this->school();
        $actor = $this->user($school);
        $periodId = $this->period($school, $actor);

        $reportId = $this->insertReport(
            $school,
            $periodId,
            $actor
        );

        $historyId = $this->insertHistory(
            $school,
            $reportId,
            $actor
        );

        $this->assertDatabaseHas(
            'teacher_duty_daily_report_history',
            [
                'id' => $historyId,
                'school_id' => $school->id,
                'daily_report_id' => $reportId,
                'actor_user_id' => $actor->id,
                'from_status' => null,
                'to_status' => 'draft',
                'event' => 'created',
            ]
        );
    }

    public function test_database_rejects_history_report_tenant_mismatch(): void
    {
        $schoolA = $this->school();
        $schoolB = $this->school();

        $actorA = $this->user($schoolA);
        $actorB = $this->user($schoolB);

        $periodB = $this->period(
            $schoolB,
            $actorB
        );

        $reportB = $this->insertReport(
            $schoolB,
            $periodB,
            $actorB
        );

        $this->expectDatabaseViolation(
            'td_daily_report_history_school_report_foreign',
            function () use (
                $schoolA,
                $reportB,
                $actorA
            ): void {
                $this->insertHistory(
                    $schoolA,
                    $reportB,
                    $actorA
                );
            }
        );
    }

    public function test_database_rejects_history_actor_tenant_mismatch(): void
    {
        $schoolA = $this->school();
        $schoolB = $this->school();

        $actorA = $this->user($schoolA);
        $actorB = $this->user($schoolB);

        $periodA = $this->period(
            $schoolA,
            $actorA
        );

        $reportA = $this->insertReport(
            $schoolA,
            $periodA,
            $actorA
        );

        $this->expectDatabaseViolation(
            'td_daily_report_history_school_actor_foreign',
            function () use (
                $schoolA,
                $reportA,
                $actorB
            ): void {
                $this->insertHistory(
                    $schoolA,
                    $reportA,
                    $actorB
                );
            }
        );
    }

    public function test_daily_report_model_prohibits_generic_deletion(): void
    {
        $school = $this->school();
        $actor = $this->user($school);
        $periodId = $this->period($school, $actor);

        $reportId = $this->insertReport(
            $school,
            $periodId,
            $actor
        );

        $report = TeacherDutyDailyReport::query()
            ->withoutGlobalScopes()
            ->findOrFail($reportId);

        try {
            $report->delete();

            $this->fail(
                'Generic Teacher Duty daily report deletion was allowed.'
            );
        } catch (\LogicException $exception) {
            $this->assertSame(
                'Teacher duty daily reports cannot be deleted.',
                $exception->getMessage()
            );
        }

        $this->assertDatabaseHas(
            'teacher_duty_daily_reports',
            [
                'id' => $reportId,
            ]
        );
    }

    public function test_daily_report_history_model_prohibits_generic_deletion(): void
    {
        $school = $this->school();
        $actor = $this->user($school);
        $periodId = $this->period($school, $actor);

        $reportId = $this->insertReport(
            $school,
            $periodId,
            $actor
        );

        $historyId = $this->insertHistory(
            $school,
            $reportId,
            $actor
        );

        $history = TeacherDutyDailyReportHistory::query()
            ->withoutGlobalScopes()
            ->findOrFail($historyId);

        try {
            $history->delete();

            $this->fail(
                'Generic Teacher Duty daily report history deletion was allowed.'
            );
        } catch (\LogicException $exception) {
            $this->assertSame(
                'Teacher duty daily report history cannot be deleted.',
                $exception->getMessage()
            );
        }

        $this->assertDatabaseHas(
            'teacher_duty_daily_report_history',
            [
                'id' => $historyId,
            ]
        );
    }

    public function test_daily_report_history_model_prohibits_updates(): void
    {
        $school = $this->school();
        $actor = $this->user($school);
        $periodId = $this->period($school, $actor);

        $reportId = $this->insertReport(
            $school,
            $periodId,
            $actor
        );

        $historyId = $this->insertHistory(
            $school,
            $reportId,
            $actor
        );

        $history = TeacherDutyDailyReportHistory::query()
            ->withoutGlobalScopes()
            ->findOrFail($historyId);

        try {
            $history->forceFill([
                'event' => 'tampered',
            ])->save();

            $this->fail(
                'Teacher Duty daily report history update was allowed.'
            );
        } catch (\LogicException $exception) {
            $this->assertSame(
                'Teacher duty daily report history cannot be updated.',
                $exception->getMessage()
            );
        }

        $this->assertDatabaseHas(
            'teacher_duty_daily_report_history',
            [
                'id' => $historyId,
                'event' => 'created',
            ]
        );
    }

    private function insertHistory(
        School $school,
        string $reportId,
        User $actor
    ): string {
        $id = (string) Str::uuid();

        DB::table('teacher_duty_daily_report_history')->insert([
            'id' => $id,
            'school_id' => $school->id,
            'daily_report_id' => $reportId,
            'actor_user_id' => $actor->id,
            'from_status' => null,
            'to_status' => 'draft',
            'event' => 'created',
            'created_at' => now(),
        ]);

        return $id;
    }

    private function insertReport(
        School $school,
        string $periodId,
        User $creator,
        string $status = 'draft',
        ?User $submittedBy = null,
        mixed $submittedAt = null
    ): string {
        $id = (string) Str::uuid();

        DB::table('teacher_duty_daily_reports')->insert([
            'id' => $id,
            'school_id' => $school->id,
            'duty_period_id' => $periodId,
            'report_date' => '2026-09-09',
            'status' => $status,
            'summary' => null,
            'deadline_at' => '2026-09-09 19:00:00+03',
            'created_by' => $creator->id,
            'submitted_by' => $submittedBy?->id,
            'submitted_at' => $submittedAt,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function period(
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

    private function expectDatabaseRejection(
        callable $callback
    ): void {
        try {
            DB::transaction(
                function () use ($callback): void {
                    $callback();
                }
            );

            $this->fail(
                'PostgreSQL accepted an invalid persisted report state.'
            );
        } catch (QueryException $exception) {
            $this->assertTrue(
                str_contains(
                    $exception->getMessage(),
                    'td_daily_reports_status_check'
                )
                || str_contains(
                    $exception->getMessage(),
                    'td_daily_reports_submission_evidence_check'
                )
            );
        }
    }

    private function school(): School
    {
        return School::query()->create([
            'id' => (string) Str::uuid(),
            'school_name' => 'Daily Report '.Str::upper(
                Str::random(8)
            ),
            'school_code' => 'TDR-'.Str::upper(
                Str::random(8)
            ),
            'short_name' => 'TDR',
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
            'role_name' => 'Daily Report Test '.Str::upper(
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
            'username' => 'daily_report_'.Str::lower(
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
