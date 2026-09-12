<?php

declare(strict_types=1);

namespace Tests\Feature\TeacherDuty;

use App\Models\Role;
use App\Models\School;
use App\Models\TeacherDutyWeeklyReport;
use App\Models\TeacherDutyWeeklyReportHistory;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class TeacherDutyWeeklyReportDatabaseIntegrityTest extends TestCase
{
    use DatabaseTransactions;

    public function test_database_accepts_valid_draft_weekly_report(): void
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
            'teacher_duty_weekly_reports',
            [
                'id' => $reportId,
                'school_id' => $school->id,
                'duty_period_id' => $periodId,
                'status' => 'draft',
                'created_by' => $actor->id,
                'submitted_by' => null,
                'submitted_at' => null,
                'reviewed_by' => null,
                'reviewed_at' => null,
            ]
        );
    }

    public function test_database_rejects_duplicate_weekly_report_identity(): void
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
            'td_weekly_reports_school_period_unique',
            function () use ($school, $periodId, $actor): void {
                $this->insertReport(
                    $school,
                    $periodId,
                    $actor
                );
            }
        );
    }

    public function test_database_rejects_weekly_report_period_tenant_mismatch(): void
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
            'td_weekly_reports_school_period_foreign',
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

    public function test_database_rejects_weekly_report_creator_tenant_mismatch(): void
    {
        $schoolA = $this->school();
        $schoolB = $this->school();

        $creatorA = $this->user($schoolA);
        $creatorB = $this->user($schoolB);

        $periodA = $this->period(
            $schoolA,
            $creatorA
        );

        $this->expectDatabaseViolation(
            'td_weekly_reports_school_created_by_foreign',
            function () use (
                $schoolA,
                $periodA,
                $creatorB
            ): void {
                $this->insertReport(
                    $schoolA,
                    $periodA,
                    $creatorB
                );
            }
        );
    }

    public function test_database_rejects_invalid_weekly_report_status(): void
    {
        $school = $this->school();
        $actor = $this->user($school);
        $periodId = $this->period($school, $actor);

        $this->expectInvalidStatusRejection(
            function () use (
                $school,
                $periodId,
                $actor
            ): void {
                $this->insertReport(
                    $school,
                    $periodId,
                    $actor,
                    'under_review'
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
            'td_weekly_reports_lifecycle_evidence_check',
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
                    now(),
                    $this->snapshot()
                );
            }
        );
    }

    public function test_database_rejects_submitted_without_snapshot(): void
    {
        $school = $this->school();
        $actor = $this->user($school);
        $periodId = $this->period($school, $actor);

        $this->expectDatabaseViolation(
            'td_weekly_reports_lifecycle_evidence_check',
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
                    now(),
                    null
                );
            }
        );
    }

    public function test_database_accepts_valid_submitted_weekly_report(): void
    {
        $school = $this->school();
        $actor = $this->user($school);
        $periodId = $this->period($school, $actor);

        $reportId = $this->insertReport(
            $school,
            $periodId,
            $actor,
            'submitted',
            $actor,
            now(),
            $this->snapshot()
        );

        $this->assertDatabaseHas(
            'teacher_duty_weekly_reports',
            [
                'id' => $reportId,
                'school_id' => $school->id,
                'status' => 'submitted',
                'submitted_by' => $actor->id,
            ]
        );

        $this->assertNotNull(
            DB::table('teacher_duty_weekly_reports')
                ->where('id', $reportId)
                ->value('evidence_snapshot')
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
            'td_weekly_reports_school_submitted_by_foreign',
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
                    now(),
                    $this->snapshot()
                );
            }
        );
    }

    public function test_database_rejects_changes_requested_without_meaningful_comment(): void
    {
        $school = $this->school();
        $creator = $this->user($school);
        $reviewer = $this->user($school);
        $periodId = $this->period($school, $creator);

        $this->expectDatabaseViolation(
            'td_weekly_reports_lifecycle_evidence_check',
            function () use (
                $school,
                $periodId,
                $creator,
                $reviewer
            ): void {
                $this->insertReport(
                    $school,
                    $periodId,
                    $creator,
                    'changes_requested',
                    $creator,
                    now(),
                    $this->snapshot(),
                    $reviewer,
                    now(),
                    '   '
                );
            }
        );
    }

    public function test_database_rejects_rejected_without_meaningful_comment(): void
    {
        $school = $this->school();
        $creator = $this->user($school);
        $reviewer = $this->user($school);
        $periodId = $this->period($school, $creator);

        $this->expectDatabaseViolation(
            'td_weekly_reports_lifecycle_evidence_check',
            function () use (
                $school,
                $periodId,
                $creator,
                $reviewer
            ): void {
                $this->insertReport(
                    $school,
                    $periodId,
                    $creator,
                    'rejected',
                    $creator,
                    now(),
                    $this->snapshot(),
                    $reviewer,
                    now(),
                    ''
                );
            }
        );
    }

    public function test_database_accepts_approved_with_null_review_comment(): void
    {
        $school = $this->school();
        $creator = $this->user($school);
        $reviewer = $this->user($school);
        $periodId = $this->period($school, $creator);

        $reportId = $this->insertReport(
            $school,
            $periodId,
            $creator,
            'approved',
            $creator,
            now(),
            $this->snapshot(),
            $reviewer,
            now(),
            null
        );

        $this->assertDatabaseHas(
            'teacher_duty_weekly_reports',
            [
                'id' => $reportId,
                'status' => 'approved',
                'reviewed_by' => $reviewer->id,
                'review_comment' => null,
            ]
        );
    }

    public function test_database_rejects_same_submitter_and_reviewer(): void
    {
        $school = $this->school();
        $actor = $this->user($school);
        $periodId = $this->period($school, $actor);

        $this->expectDatabaseViolation(
            'td_weekly_reports_reviewer_separation_check',
            function () use (
                $school,
                $periodId,
                $actor
            ): void {
                $this->insertReport(
                    $school,
                    $periodId,
                    $actor,
                    'approved',
                    $actor,
                    now(),
                    $this->snapshot(),
                    $actor,
                    now(),
                    null
                );
            }
        );
    }

    public function test_database_rejects_reviewer_tenant_mismatch(): void
    {
        $schoolA = $this->school();
        $schoolB = $this->school();

        $creatorA = $this->user($schoolA);
        $reviewerB = $this->user($schoolB);

        $periodA = $this->period(
            $schoolA,
            $creatorA
        );

        $this->expectDatabaseViolation(
            'td_weekly_reports_school_reviewed_by_foreign',
            function () use (
                $schoolA,
                $periodA,
                $creatorA,
                $reviewerB
            ): void {
                $this->insertReport(
                    $schoolA,
                    $periodA,
                    $creatorA,
                    'approved',
                    $creatorA,
                    now(),
                    $this->snapshot(),
                    $reviewerB,
                    now(),
                    null
                );
            }
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
            'teacher_duty_weekly_report_history',
            [
                'id' => $historyId,
                'school_id' => $school->id,
                'weekly_report_id' => $reportId,
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
            'td_weekly_report_history_school_report_foreign',
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
            'td_weekly_report_history_school_actor_foreign',
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

    public function test_weekly_report_model_prohibits_generic_deletion(): void
    {
        $school = $this->school();
        $actor = $this->user($school);
        $periodId = $this->period($school, $actor);

        $reportId = $this->insertReport(
            $school,
            $periodId,
            $actor
        );

        $report = TeacherDutyWeeklyReport::query()
            ->withoutGlobalScopes()
            ->findOrFail($reportId);

        try {
            $report->delete();

            $this->fail(
                'Generic Teacher Duty weekly report deletion was allowed.'
            );
        } catch (\LogicException $exception) {
            $this->assertSame(
                'Teacher duty weekly reports cannot be deleted.',
                $exception->getMessage()
            );
        }

        $this->assertDatabaseHas(
            'teacher_duty_weekly_reports',
            [
                'id' => $reportId,
            ]
        );
    }

    public function test_weekly_report_history_model_prohibits_generic_deletion(): void
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

        $history = TeacherDutyWeeklyReportHistory::query()
            ->withoutGlobalScopes()
            ->findOrFail($historyId);

        try {
            $history->delete();

            $this->fail(
                'Generic Teacher Duty weekly report history deletion was allowed.'
            );
        } catch (\LogicException $exception) {
            $this->assertSame(
                'Teacher duty weekly report history cannot be deleted.',
                $exception->getMessage()
            );
        }

        $this->assertDatabaseHas(
            'teacher_duty_weekly_report_history',
            [
                'id' => $historyId,
            ]
        );
    }

    public function test_weekly_report_history_model_prohibits_updates(): void
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

        $history = TeacherDutyWeeklyReportHistory::query()
            ->withoutGlobalScopes()
            ->findOrFail($historyId);

        try {
            $history->forceFill([
                'event' => 'tampered',
            ])->save();

            $this->fail(
                'Teacher Duty weekly report history update was allowed.'
            );
        } catch (\LogicException $exception) {
            $this->assertSame(
                'Teacher duty weekly report history cannot be updated.',
                $exception->getMessage()
            );
        }

        $this->assertDatabaseHas(
            'teacher_duty_weekly_report_history',
            [
                'id' => $historyId,
                'event' => 'created',
            ]
        );
    }

    private function insertReport(
        School $school,
        string $periodId,
        User $creator,
        string $status = 'draft',
        ?User $submittedBy = null,
        mixed $submittedAt = null,
        ?array $snapshot = null,
        ?User $reviewedBy = null,
        mixed $reviewedAt = null,
        ?string $reviewComment = null
    ): string {
        $id = (string) Str::uuid();

        DB::table('teacher_duty_weekly_reports')->insert([
            'id' => $id,
            'school_id' => $school->id,
            'duty_period_id' => $periodId,
            'status' => $status,
            'summary' => null,
            'highlights' => null,
            'challenges' => null,
            'recommendations' => null,
            'evidence_snapshot' => $snapshot === null
                ? null
                : json_encode(
                    $snapshot,
                    JSON_THROW_ON_ERROR
                ),
            'created_by' => $creator->id,
            'submitted_by' => $submittedBy?->id,
            'submitted_at' => $submittedAt,
            'reviewed_by' => $reviewedBy?->id,
            'reviewed_at' => $reviewedAt,
            'review_comment' => $reviewComment,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function insertHistory(
        School $school,
        string $reportId,
        User $actor
    ): string {
        $id = (string) Str::uuid();

        DB::table('teacher_duty_weekly_report_history')->insert([
            'id' => $id,
            'school_id' => $school->id,
            'weekly_report_id' => $reportId,
            'actor_user_id' => $actor->id,
            'from_status' => null,
            'to_status' => 'draft',
            'event' => 'created',
            'comment' => null,
            'evidence_snapshot' => null,
            'created_at' => now(),
        ]);

        return $id;
    }

    private function snapshot(): array
    {
        return [
            'schema_version' => 1,
            'daily_reports' => [
                'expected' => 5,
                'not_started' => 1,
                'draft' => 1,
                'overdue' => 1,
                'submitted' => 2,
                'late_submitted' => 1,
            ],
            'occurrences' => [
                'total' => 0,
            ],
        ];
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

    private function expectInvalidStatusRejection(
        callable $callback
    ): void {
        try {
            DB::transaction(
                function () use ($callback): void {
                    $callback();
                }
            );

            $this->fail(
                'PostgreSQL accepted an invalid weekly report status.'
            );
        } catch (QueryException $exception) {
            $message = $exception->getMessage();

            $this->assertTrue(
                str_contains(
                    $message,
                    'td_weekly_reports_status_check'
                )
                || str_contains(
                    $message,
                    'td_weekly_reports_lifecycle_evidence_check'
                )
            );
        }
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

    private function school(): School
    {
        return School::query()->create([
            'id' => (string) Str::uuid(),
            'school_name' => 'Weekly Report '.Str::upper(
                Str::random(8)
            ),
            'school_code' => 'TWR-'.Str::upper(
                Str::random(8)
            ),
            'short_name' => 'TWR',
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
            'role_name' => 'Weekly Report Test '.Str::upper(
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
            'username' => 'weekly_report_'.Str::lower(
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
