<?php

declare(strict_types=1);

namespace Tests\Feature\Boarding;

use App\Models\AuditLog;
use App\Models\User;
use App\Services\Boarding\BoardingDailyReportService;
use App\Services\Boarding\BoardingOccurrenceCategoryService;
use App\Services\Boarding\BoardingReportOccurrenceService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\Support\Database\AcademicYearBuilder;
use Tests\Support\Database\GradeBuilder;
use Tests\Support\Database\RoleBuilder;
use Tests\Support\Database\SchoolBuilder;
use Tests\Support\Database\StreamBuilder;
use Tests\Support\Database\TermBuilder;
use Tests\Support\Database\UserBuilder;
use Tests\TestCase;

class BoardingOccurrenceCategoryHttpTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        if (! app()->environment('testing')
            || DB::connection()->getDriverName() !== 'pgsql'
            || ! in_array(
                DB::selectOne('SELECT current_database() AS name')->name,
                ['shuleos_assessment_fk_test', 'shuleos_testing'],
                true
            )) {
            throw new \RuntimeException('An isolated PostgreSQL test database is required.');
        }

        config([
            'jwt.secret' => str_repeat('boarding-category-http-secret-', 3),
            'jwt.ttl' => 60,
        ]);
    }

    public function test_category_http_rename_preserves_submitted_snapshot(): void
    {
        $f = $this->operationalFixture();
        $this->grant($f['user'], 'configure_boarding_reporting');
        $this->grant($f['user'], 'record_boarding_operations');
        $this->httpAs($f['user']);

        $category = $this->postJson('/api/boarding/reporting/occurrence-categories', [
            'category_name' => 'Facilities', 'active' => true,
        ])->assertCreated()->json('data');

        $reports = app(BoardingDailyReportService::class);
        $report = $reports->open($f['user'], $f['obligation']);
        $occurrence = app(BoardingReportOccurrenceService::class)
            ->record($f['user'], $report['revision_id'], 1, [
                'category_id' => $category['id'],
                'occurred_at' => '2026-11-02T00:30:00+03:00',
                'description' => 'Facilities checked.',
                'actions_taken' => '',
                'follow_up_required' => false,
                'follow_up_notes' => '',
            ]);
        $draft = $reports->updateDraft(
            $f['user'], $report['revision_id'], $occurrence['version'],
            ['summary' => 'Daily checks.', 'no_occurrences' => false]
        );
        $submitted = $reports->submit($f['user'], $draft['revision_id'], $draft['version']);
        $snapshot = DB::table('boarding_daily_report_submissions')
            ->where('id', $submitted['submission_id'])->value('snapshot');

        $this->patchJson('/api/boarding/reporting/occurrence-categories/'.$category['id'], [
            'expected_version' => $category['version'],
            'category_name' => 'Maintenance',
            'active' => false,
        ])->assertOk()->assertJsonPath('data.active', false);

        $this->assertSame($snapshot, DB::table('boarding_daily_report_submissions')
            ->where('id', $submitted['submission_id'])->value('snapshot'));
        $this->assertSame('Facilities', $submitted['snapshot']['occurrences'][0]['category_name']);

        $before = $this->categorySnapshot();
        $this->getJson('/api/boarding/reporting/occurrence-categories?per_page=1')
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.category_name', 'Maintenance');
        $this->assertSame($before, $this->categorySnapshot());
    }

    public function test_category_versions_do_not_repeat_when_values_are_restored(): void
    {
        $f = $this->operationalFixture();
        $this->grant($f['user'], 'configure_boarding_reporting');
        $this->httpAs($f['user']);

        foreach (['name', 'active'] as $field) {
            $original = [
                'category_name' => 'Revision check '.$field,
                'active' => true,
            ];
            $category = $this->postJson(
                '/api/boarding/reporting/occurrence-categories', $original
            )->assertCreated()->json('data');

            $url = '/api/boarding/reporting/occurrence-categories/'.$category['id'];
            $changedValues = $field === 'name'
                ? array_replace($original, ['category_name' => 'Renamed category'])
                : array_replace($original, ['active' => false]);

            $changed = $this->patchJson($url, [
                'expected_version' => $category['version'],
            ] + $changedValues)->assertOk()->json('data');

            $restored = $this->patchJson($url, [
                'expected_version' => $changed['version'],
            ] + $original)->assertOk()->json('data');

            $this->assertNotSame($category['version'], $changed['version']);
            $this->assertNotSame($category['version'], $restored['version']);
            $this->assertNotSame($changed['version'], $restored['version']);
            $this->assertSame($original['category_name'], $restored['category_name']);
            $this->assertTrue($restored['active']);

            $row = DB::table('boarding_occurrence_categories')
                ->where('id', $category['id'])->first();
            $this->assertSame(3, (int) $row->lock_version);

            $before = $this->categorySnapshot();

            foreach ([$category['version'], $changed['version']] as $stale) {
                $this->patchJson($url, [
                    'expected_version' => $stale,
                    'category_name' => 'Stale replacement',
                    'active' => false,
                ])->assertConflict();
            }

            $this->assertSame($before, $this->categorySnapshot());

            // A genuine no-op keeps its token and creates no audit record.
            $noop = $this->patchJson($url, [
                'expected_version' => $restored['version'],
            ] + $original)->assertOk()->json('data');

            $this->assertSame($restored['version'], $noop['version']);
            $this->assertSame($before, $this->categorySnapshot());

            $listed = collect($this->getJson(
                '/api/boarding/reporting/occurrence-categories'
            )->assertOk()->json('data'))->firstWhere('id', $category['id']);
            $this->assertNotNull($listed);
            $this->assertSame($restored['version'], $listed['version']);

            // Direct SQL updates must also advance the revision.
            DB::table('boarding_occurrence_categories')
                ->where('id', $category['id'])
                ->update(['active' => false, 'lock_version' => 999]);
            $this->assertSame(4, (int) DB::table('boarding_occurrence_categories')
                ->where('id', $category['id'])->value('lock_version'));
        }
    }

    public function test_category_http_stale_duplicate_and_inactive_category_writes_fail(): void
    {
        $f = $this->operationalFixture();
        $this->grant($f['user'], 'configure_boarding_reporting');
        $this->grant($f['user'], 'record_boarding_operations');
        $this->httpAs($f['user']);
        $category = $this->postJson('/api/boarding/reporting/occurrence-categories', [
            'category_name' => 'Safety', 'active' => true,
        ])->assertCreated()->json('data');

        $updated = $this->patchJson('/api/boarding/reporting/occurrence-categories/'.$category['id'], [
            'expected_version' => $category['version'],
            'category_name' => 'Safety', 'active' => false,
        ])->assertOk()->json('data');
        $before = $this->categorySnapshot();

        $this->patchJson('/api/boarding/reporting/occurrence-categories/'.$category['id'], [
            'expected_version' => $category['version'],
            'category_name' => 'Stale rename', 'active' => true,
        ])->assertConflict();
        $this->postJson('/api/boarding/reporting/occurrence-categories', [
            'category_name' => ' safety ', 'active' => true,
        ])->assertUnprocessable()->assertJsonValidationErrors('category_name');
        $this->assertSame($before, $this->categorySnapshot());

        $report = app(BoardingDailyReportService::class)->open($f['user'], $f['obligation']);
        $before = $this->categorySnapshot();
        $this->postJson(
            '/api/boarding/reporting/revisions/'.$report['revision_id'].'/occurrences',
            [
                'version' => 1,
                'category_id' => $updated['id'],
                'occurred_at' => '2026-11-02T00:30:00+03:00',
                'description' => 'Inactive category attempt.',
                'actions_taken' => '',
                'follow_up_required' => false,
                'follow_up_notes' => '',
            ]
        )->assertUnprocessable()->assertJsonValidationErrors('category_id');
        $this->assertSame($before, $this->categorySnapshot());
    }

    public function test_category_http_foreign_updates_and_lists_fail_closed_both_ways(): void
    {
        $a = $this->operationalFixture();
        $b = $this->operationalFixture();
        $service = app(BoardingOccurrenceCategoryService::class);
        foreach ([$a, $b] as $f) {
            $this->grant($f['user'], 'configure_boarding_reporting');
        }
        $a['category'] = $service->create($a['user'], ['category_name' => 'School A', 'active' => true]);
        $b['category'] = $service->create($b['user'], ['category_name' => 'School B', 'active' => true]);
        $before = $this->categorySnapshot();

        foreach ([[$a, $b], [$b, $a]] as [$own, $foreign]) {
            $this->httpAs($own['user']);
            $this->getJson('/api/boarding/reporting/occurrence-categories')
                ->assertOk()->assertJsonCount(1, 'data')
                ->assertJsonPath('data.0.id', $own['category']['id']);
            $this->patchJson(
                '/api/boarding/reporting/occurrence-categories/'.$foreign['category']['id'],
                [
                    'expected_version' => $foreign['category']['version'],
                    'category_name' => 'Foreign rename', 'active' => false,
                ]
            )->assertNotFound();
        }
        $this->assertSame($before, $this->categorySnapshot());
    }

    public function test_category_http_reporter_can_read_catalogue_but_cannot_configure_it(): void
    {
        $f = $this->operationalFixture();
        $this->grant($f['user'], 'record_boarding_operations');
        $this->httpAs($f['user']);
        $before = $this->categorySnapshot();
        $this->getJson('/api/boarding/reporting/occurrence-categories')->assertOk();
        $this->postJson('/api/boarding/reporting/occurrence-categories', [
            'category_name' => 'Unauthorized', 'active' => true,
        ])->assertForbidden();
        $this->assertSame($before, $this->categorySnapshot());
    }

    public function test_category_service_audit_failure_rolls_back_create_and_update(): void
    {
        $f = $this->operationalFixture();
        $this->grant($f['user'], 'configure_boarding_reporting');
        $service = app(BoardingOccurrenceCategoryService::class);
        $data = ['category_name' => 'Audit rollback', 'active' => true];
        $before = $this->categorySnapshot();
        $this->failingAudit(fn () => $service->create($f['user'], $data));
        $this->assertSame($before, $this->categorySnapshot());

        $category = $service->create($f['user'], $data);
        $before = $this->categorySnapshot();
        $this->failingAudit(fn () => $service->update(
            $f['user'], $category['id'], $category['version'],
            ['category_name' => 'Must roll back', 'active' => false]
        ));
        $this->assertSame($before, $this->categorySnapshot());
    }

    public function test_category_http_authentication_authoritative_fields_and_pagination_are_checked(): void
    {
        $this->getJson('/api/boarding/reporting/occurrence-categories')->assertUnauthorized();
        $f = $this->operationalFixture();
        $this->grant($f['user'], 'configure_boarding_reporting');
        $this->httpAs($f['user']);
        $before = $this->categorySnapshot();

        $this->postJson('/api/boarding/reporting/occurrence-categories', [
            'category_name' => 'Injected', 'active' => true,
            'school_id' => $f['school']->id, 'created_by' => $f['user']->id,
        ])->assertUnprocessable()->assertJsonValidationErrors(['school_id', 'created_by']);
        $this->getJson('/api/boarding/reporting/occurrence-categories?per_page=101')
            ->assertUnprocessable()->assertJsonValidationErrors('per_page');

        $this->assertSame($before, $this->categorySnapshot());
    }

    private function categorySnapshot(): array
    {
        $snapshot = $this->serviceSnapshot();
        $snapshot['categories'] = DB::table('boarding_occurrence_categories')
            ->orderBy('id')->get()->toJson();

        return $snapshot;
    }

    private function httpAs(User $user): void
    {
        $token = JWTAuth::fromUser($user);
        JWTAuth::setToken($token);
        $this->withToken($token);
    }

    private function operationalFixture(): array
    {
        $f = $this->fixture(false);

        DB::table('schools')->where('id', $f['school']->id)->update([
            'short_name' => 'BRD',
            'registration_number' => 'REG-'.Str::random(12),
            'school_type' => 'Primary',
            'operating_mode' => 'day_and_boarding',
            'county' => 'Nairobi',
            'phone' => '+254700000001',
            'email' => 'office-'.Str::random(12).'@example.test',
            'timezone' => 'Africa/Nairobi',
            'locale' => 'en',
            'lifecycle_state' => 'active',
        ]);

        $year = AcademicYearBuilder::create($f['school']);
        TermBuilder::create($f['school'], $year);
        $grade = GradeBuilder::create($f['school']);
        StreamBuilder::create($f['school'], $grade);

        $f['user'] = User::withoutGlobalScopes()->findOrFail($f['user']->id);
        $f['reviewer'] = User::withoutGlobalScopes()->findOrFail($f['reviewer']->id);

        return $f;
    }

    private function grant(User $user, string $name): void
    {
        $id = DB::table('permissions')->where('permission_name', $name)->value('id');
        $this->assertNotNull($id);

        DB::table('role_permissions')->insertOrIgnore([
            'id' => (string) Str::uuid(),
            'role_id' => $user->role_id,
            'permission_id' => $id,
            'created_at' => now(),
        ]);
    }

    private function serviceSnapshot(): array
    {
        $snapshot = [];
        foreach ([
            'boarding_daily_reports', 'boarding_daily_report_revisions',
            'boarding_daily_report_contributors', 'boarding_daily_report_submissions',
            'boarding_daily_report_reviews', 'boarding_report_occurrence_events',
            'audit_logs', 'fee_invoices', 'payments', 'learner_fee_ledger',
        ] as $table) {
            $snapshot[$table] = DB::table($table)->orderBy('id')->get()->toJson();
        }

        return $snapshot;
    }

    private function denied(callable $operation, int $status): void
    {
        try {
            $operation();
            $this->fail('Unauthorized or conflicting reporting operation succeeded.');
        } catch (HttpExceptionInterface $exception) {
            $this->assertSame($status, $exception->getStatusCode());
        }
    }

    private function failingAudit(callable $operation): void
    {
        $dispatcher = AuditLog::getEventDispatcher();
        AuditLog::setEventDispatcher(clone $dispatcher);

        try {
            AuditLog::creating(static function (): void {
                throw new \RuntimeException('Reporting audit failure test.');
            });

            try {
                $operation();
                $this->fail('A failed audit did not abort the reporting mutation.');
            } catch (\RuntimeException $exception) {
                $this->assertSame('Reporting audit failure test.', $exception->getMessage());
            }
        } finally {
            AuditLog::setEventDispatcher($dispatcher);
        }
    }

    private function fixture(bool $withReport = true): array
    {
        $school = SchoolBuilder::create();
        $role = RoleBuilder::create();
        $user = UserBuilder::create($school, $role);
        $reviewer = UserBuilder::create($school, RoleBuilder::create());

        $hostel = $this->insert('hostels', [
            'school_id' => $school->id,
            'hostel_name' => 'Daily reporting '.Str::random(10),
            'hostel_type' => 'BOYS',
            'capacity' => 20,
            'active' => true,
            'is_deleted' => false,
        ]);

        $f = compact('school', 'user', 'reviewer', 'hostel');
        $f['assignment'] = $this->assignment($f, (string) $user->id);

        $policy = $this->insert('boarding_reporting_policies', [
            'school_id' => $school->id,
            'revision' => 1,
            'enabled' => true,
            'effective_from' => '2026-11-01',
            'timezone' => 'Africa/Nairobi',
            'deadline_time' => '08:00',
            'deadline_day_offset' => 1,
            'grace_minutes' => 30,
            'weekday_mask' => 127,
            'created_by' => $user->id,
        ]);

        $f['obligation'] = $this->insert('boarding_daily_obligations', [
            'school_id' => $school->id,
            'hostel_id' => $hostel,
            'policy_id' => $policy,
            'reporting_date' => '2026-11-02',
            'created_by' => $user->id,
        ]);

        if ($withReport) {
            $f['report'] = $this->createReportHead($f);
            $f['revision'] = $this->draft($f);
        }

        return $f;
    }

    private function assignment(array $f, string $userId): string
    {
        return $this->insert('hostel_staff_assignments', [
            'school_id' => $f['school']->id,
            'hostel_id' => $f['hostel'],
            'user_id' => $userId,
            'responsibility_role' => 'Warden',
            'effective_from' => '2026-11-01',
            'active' => true,
            'assigned_by' => $f['user']->id,
        ]);
    }

    private function createReportHead(array $f, array $override = []): string
    {
        return $this->insert('boarding_daily_reports', array_merge([
            'school_id' => $f['school']->id,
            'obligation_id' => $f['obligation'],
            'created_by' => $f['user']->id,
        ], $override));
    }

    private function draft(array $f, int $number = 1, array $override = []): string
    {
        return $this->insert('boarding_daily_report_revisions', array_merge([
            'school_id' => $f['school']->id,
            'report_id' => $f['report'],
            'revision_number' => $number,
            'summary' => 'Initial report',
            'no_occurrences' => true,
            'created_by' => $f['user']->id,
            'created_assignment_id' => $f['assignment'],
            'updated_by' => $f['user']->id,
            'updated_assignment_id' => $f['assignment'],
        ], $override));
    }

    private function submit(array $f, ?string $revision = null, array $override = []): string
    {
        return $this->insert('boarding_daily_report_submissions', array_merge([
            'school_id' => $f['school']->id,
            'revision_id' => $revision ?? $f['revision'],
            'submitted_by' => $f['user']->id,
            'assignment_id' => $f['assignment'],
        ], $override));
    }

    private function review(
        array $f,
        string $submission,
        string $decision = 'reviewed',
        array $override = []
    ): string {
        $revision = DB::table('boarding_daily_report_submissions')
            ->where('id', $submission)->value('revision_id');

        return $this->insert('boarding_daily_report_reviews', array_merge([
            'school_id' => $f['school']->id,
            'revision_id' => $revision,
            'submission_id' => $submission,
            'reviewed_by' => $f['reviewer']->id,
            'decision' => $decision,
            'comment' => $decision === 'changes_requested' ? 'Please correct the report.' : null,
        ], $override));
    }

    private function insert(string $table, array $values): string
    {
        $id = (string) Str::uuid();
        DB::table($table)->insert(['id' => $id] + $values);

        return $id;
    }

    private function rejected(callable $operation, string $sqlState): void
    {
        DB::beginTransaction();

        try {
            $operation();
            $this->fail('Invalid report evidence write was accepted.');
        } catch (QueryException $exception) {
            $this->assertSame($sqlState, $exception->errorInfo[0] ?? null);
        } finally {
            DB::rollBack();
        }
    }
}
