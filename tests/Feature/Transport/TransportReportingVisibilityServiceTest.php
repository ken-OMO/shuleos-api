<?php

declare(strict_types=1);

namespace Tests\Feature\Transport;

use App\Models\User;
use App\Services\SchoolSetupReadinessService;
use App\Services\Transport\TransportAssignmentService;
use App\Services\Transport\TransportDailyReportService;
use App\Services\Transport\TransportHandoverAuthorizationService;
use App\Services\Transport\TransportJourneyEventService;
use App\Services\Transport\TransportLearnerReservationService;
use App\Services\Transport\TransportReportingObligationService;
use App\Services\Transport\TransportReportingVisibilityService;
use App\Services\Transport\TransportTripService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\Support\Database\GradeBuilder;
use Tests\Support\Database\LearnerBuilder;
use Tests\Support\Database\RoleBuilder;
use Tests\Support\Database\SchoolBuilder;
use Tests\Support\Database\StreamBuilder;
use Tests\Support\Database\UserBuilder;
use Tests\Support\TransportSubscriptionFixture;
use Tests\TestCase;

class TransportReportingVisibilityServiceTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $config = DB::connection()->getConfig();

        if (! app()->environment('testing')
            || app()->configurationIsCached()
            || ($config['driver'] ?? null) !== 'pgsql'
            || ($config['database'] ?? null) !== 'shuleos_assessment_fk_test'
            || ! empty($config['url'])
            || DB::selectOne('SELECT current_database() AS name')->name
                !== 'shuleos_assessment_fk_test') {
            throw new \RuntimeException('Exact isolated Transport database required.');
        }

        CarbonImmutable::setTestNow('2026-11-09T02:00:00Z');
        $this->mock(SchoolSetupReadinessService::class)
            ->shouldReceive('isReady')->andReturn(true);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_unmaterialized_service_without_runs_remains_visible_and_unassigned(): void
    {
        $f = $this->fixture();
        $schedule = $this->emptySchedule($f);
        $policy = $this->policy($f, ['schedule_id' => $schedule]);
        $counts = $this->evidenceCounts($f);
        $result = $this->visibility()->listing($f['actor']);
        $this->assertSame(1, $result['meta']['total']);
        $row = $result['data'][0];
        $this->assertSame($policy['id'], $row['policy_id']);
        $this->assertSame('missing', $row['report_state']);
        $this->assertSame('pending', $row['materialization_state']);
        $this->assertSame('policy_preview', $row['deadline_source']);
        $this->assertNull($row['obligation_id']);
        $this->assertSame('unassigned', $row['responsibility_state']);
        $this->assertSame([], $row['responsible_staff']);
        $this->assertSame(1, $result['summary']['not_materialized']);
        $this->assertSame($counts, $this->evidenceCounts($f));
    }

    public function test_materialized_missing_report_keeps_its_original_deadline(): void
    {
        $f = $this->fixture();
        $policy = $this->policy($f, ['schedule_id' => $this->emptySchedule($f)]);
        $obligation = $this->reporting()->materialize($f['actor'], $policy['id']);
        $counts = $this->evidenceCounts($f);
        $result = $this->visibility()->listing($f['actor']);
        $this->assertSame(1, $result['meta']['total']);
        $row = $result['data'][0];
        $this->assertSame($obligation['id'], $row['obligation_id']);
        $this->assertSame('materialized', $row['materialization_state']);
        $this->assertSame('materialized_obligation', $row['deadline_source']);
        $this->assertSame('missing', $row['report_state']);
        $this->assertSame('unassigned', $row['responsibility_state']);
        $this->assertTrue(CarbonImmutable::parse($row['deadline_at'])->equalTo(CarbonImmutable::parse($obligation['deadline_at'])));
        $this->assertSame($counts, $this->evidenceCounts($f));
    }

    public function test_deadline_and_grace_equality_have_precise_overdue_boundaries(): void
    {
        $f = $this->fixture();
        $this->policy($f);
        CarbonImmutable::setTestNow('2026-11-09T14:00:00Z');
        $row = $this->visibility()->listing($f['actor'])['data'][0];
        $this->assertFalse($row['deadline_passed']);
        $this->assertFalse($row['overdue']);
        CarbonImmutable::setTestNow('2026-11-09T14:00:01Z');
        $row = $this->visibility()->listing($f['actor'])['data'][0];
        $this->assertTrue($row['deadline_passed']);
        $this->assertFalse($row['overdue']);
        CarbonImmutable::setTestNow('2026-11-09T14:30:00Z');
        $this->assertFalse($this->visibility()->listing($f['actor'])['data'][0]['overdue']);
        CarbonImmutable::setTestNow('2026-11-09T14:30:01Z');
        $late = $this->visibility()->listing($f['actor'], ['overdue' => true]);
        $this->assertSame(1, $late['meta']['total']);
        $this->assertTrue($late['data'][0]['overdue']);
        $this->assertSame(1, $late['summary']['missing']);
        $this->assertSame(0, $this->visibility()->listing($f['actor'], ['overdue' => false])['meta']['total']);
    }

    public function test_submitted_and_accepted_reports_are_not_counted_as_outstanding_overdue(): void
    {
        $f = $this->dailyFixture();
        CarbonImmutable::setTestNow('2026-11-09T14:00:00Z');
        $submission = $this->submitDaily($f)['submissions'][0];
        CarbonImmutable::setTestNow('2026-11-09T14:30:01Z');
        $row = $this->visibility()->listing($f['actor'])['data'][0];
        $this->assertSame('submitted', $row['report_state']);
        $this->assertFalse($row['overdue']);
        $this->assertTrue($row['grace_expired']);
        $this->assertFalse($row['first_submission_late']);
        $this->assertSame($submission['id'], $row['latest_submission_id']);
        $this->daily()->review($this->reviewer($f), $submission['id'], [
            'expected_version' => $submission['review_version'], 'decision' => 'accepted',
            'note' => 'Independent review of the submitted service account.',
        ]);
        $accepted = $this->visibility()->listing($f['actor'], ['report_state' => 'accepted']);
        $this->assertSame(1, $accepted['summary']['accepted']);
        $this->assertFalse($accepted['data'][0]['overdue']);
        // Review status is not a claim that live custody exceptions were resolved.
        $this->assertArrayNotHasKey('custody_resolved', $accepted['data'][0]);
    }

    public function test_changes_requested_are_outstanding_without_rewriting_first_submission_timing(): void
    {
        $f = $this->dailyFixture();
        CarbonImmutable::setTestNow('2026-11-09T14:00:00Z');
        $first = $this->submitDaily($f)['submissions'][0];
        $this->daily()->review($this->reviewer($f), $first['id'], [
            'expected_version' => $first['review_version'], 'decision' => 'changes_requested',
            'note' => 'Clarify the outstanding service and handover account.',
        ]);
        CarbonImmutable::setTestNow('2026-11-09T14:30:01Z');
        $row = $this->visibility()->listing($f['actor'], ['overdue' => true])['data'][0];
        $this->assertSame('changes_requested', $row['report_state']);
        $this->assertFalse($row['first_submission_late']);
        $this->assertFalse($row['first_submission_after_grace']);
        $second = $this->submitDaily($f)['submissions'][1];
        $row = $this->visibility()->listing($f['actor'])['data'][0];
        $this->assertSame('submitted', $row['report_state']);
        $this->assertSame(2, $row['latest_revision']);
        $this->assertSame($second['id'], $row['latest_submission_id']);
        $this->assertFalse($row['overdue']);
        $this->assertFalse($row['first_submission_late']);
    }

    public function test_late_first_submission_remains_late_after_review(): void
    {
        $f = $this->dailyFixture();
        CarbonImmutable::setTestNow('2026-11-09T14:30:01Z');
        $submission = $this->submitDaily($f)['submissions'][0];
        $this->daily()->review($this->reviewer($f), $submission['id'], [
            'expected_version' => $submission['review_version'], 'decision' => 'accepted',
            'note' => 'Review does not rewrite the original submission time.',
        ]);
        $row = $this->visibility()->listing($f['actor'])['data'][0];
        $this->assertSame('accepted', $row['report_state']);
        $this->assertTrue($row['first_submission_late']);
        $this->assertTrue($row['first_submission_after_grace']);
        $this->assertFalse($row['overdue']);
    }

    public function test_assignment_and_current_submission_permission_are_both_required(): void
    {
        $f = $this->journey();
        $this->reporting()->materialize($f['actor'], $this->policy($f)['id']);
        $before = $this->visibility()->listing($f['actor']);
        $this->assertSame('unassigned', $before['data'][0]['responsibility_state']);
        $this->grant($f['actor'], 'submit_transport_daily_reports');
        $assigned = $this->visibility()->listing($f['actor'], ['responsibility' => 'assigned']);
        $this->assertSame(1, $assigned['meta']['total']);
        $this->assertSame([$f['actor']->id], array_column($assigned['data'][0]['responsible_staff'], 'id'));
        $this->assertSame('current_eligible_assigned_staff', $assigned['meta']['responsibility_basis']);
        // The unassigned school counterpart shares permissions, but is not crew.
        $this->assertNotContains($f['receiver']->id, array_column($assigned['data'][0]['responsible_staff'], 'id'));
        $permission = DB::table('permissions')->where('permission_name', 'submit_transport_daily_reports')->value('id');
        DB::table('role_permissions')->where('role_id', $f['actor']->role_id)->where('permission_id', $permission)->delete();
        $this->assertSame(1, $this->visibility()->listing($f['actor'], ['responsibility' => 'unassigned'])['meta']['total']);
    }

    public function test_ineligible_assigned_staff_do_not_hide_the_missing_report(): void
    {
        $f = $this->dailyFixture();
        $viewer = $this->reviewer($f);
        foreach ([
            ['active' => false],
            ['is_deleted' => true],
            ['suspended_at' => '2026-11-09 01:00:00+00'],
            ['account_locked_until' => '2026-11-10 01:00:00+00'],
        ] as $attributes) {
            DB::table('users')->where('id', $f['actor']->id)->update([
                'active' => true, 'is_deleted' => false, 'suspended_at' => null,
                'account_locked_until' => null, ...$attributes,
            ]);
            $result = $this->visibility()->listing($viewer, ['responsibility' => 'unassigned']);
            $this->assertSame(1, $result['meta']['total']);
            $this->assertSame('missing', $result['data'][0]['report_state']);
            $this->assertSame([], $result['data'][0]['responsible_staff']);
        }
    }

    public function test_foreign_schedules_and_report_rows_are_tenant_scoped_both_ways(): void
    {
        $a = $this->dailyFixture();
        $b = $this->dailyFixture();
        foreach ([[$a, $b], [$b, $a]] as [$owner, $foreign]) {
            $schedule = DB::table('transport_dated_assignments')->where('id', $foreign['assignment']['id'])->value('schedule_id');
            $this->denied(fn () => $this->visibility()->listing($owner['actor'], ['schedule_id' => $schedule]), 404);
            $result = $this->visibility()->listing($owner['actor']);
            $this->assertSame(1, $result['meta']['total']);
            $this->assertSame($owner['obligation'], $result['data'][0]['obligation_id']);
        }
    }

    public function test_management_permission_does_not_grant_reporting_visibility(): void
    {
        $f = $this->dailyFixture();
        $permission = DB::table('permissions')->where('permission_name', 'view_transport_operations')->value('id');
        DB::table('role_permissions')->where('role_id', $f['actor']->role_id)->where('permission_id', $permission)->delete();
        $this->denied(fn () => $this->visibility()->listing($f['actor']), 403);
        $this->assertSame(1, $this->visibility()->listing($this->reviewer($f))['meta']['total']);
    }

    public function test_filters_and_pagination_keep_correct_totals_on_empty_pages(): void
    {
        $f = $this->fixture();
        for ($i = 0; $i < 3; $i++) {
            $policy = $this->policy($f, ['schedule_id' => $this->emptySchedule($f)]);
            if ($i === 0) {
                $this->reporting()->materialize($f['actor'], $policy['id']);
            }
        }
        $first = $this->visibility()->listing($f['actor'], ['limit' => 1]);
        $second = $this->visibility()->listing($f['actor'], ['limit' => 1, 'offset' => 1]);
        $this->assertSame(3, $first['meta']['total']);
        $this->assertSame(3, $first['summary']['missing']);
        $this->assertCount(1, $first['data']);
        $this->assertNotSame($first['data'][0]['schedule_id'], $second['data'][0]['schedule_id']);
        $empty = $this->visibility()->listing($f['actor'], ['offset' => 100]);
        $this->assertSame([], $empty['data']);
        $this->assertSame(3, $empty['meta']['total']);
        $this->assertSame(2, $this->visibility()->listing($f['actor'], ['materialization' => 'pending'])['meta']['total']);
        $this->assertSame(1, $this->visibility()->listing($f['actor'], ['materialization' => 'materialized'])['meta']['total']);
        $this->assertSame(0, $this->visibility()->listing($f['actor'], ['responsibility' => 'assigned'])['meta']['total']);
    }

    public function test_future_dates_and_non_service_weekdays_do_not_create_missing_reports(): void
    {
        $f = $this->fixture();
        $this->policy($f, ['weekday_mask' => 2]); // Tuesday; fixture date is Monday.
        $result = $this->visibility()->listing($f['actor'], ['date_from' => '2026-11-09', 'date_to' => '2026-11-10']);
        $this->assertSame(0, $result['meta']['total']);
        CarbonImmutable::setTestNow('2026-11-10T04:00:00Z');
        $result = $this->visibility()->listing($f['actor'], ['date_from' => '2026-11-09', 'date_to' => '2026-11-11']);
        $this->assertSame(1, $result['meta']['total']);
        $this->assertSame('2026-11-10', $result['data'][0]['service_date']);
        $this->assertSame('pending', $result['data'][0]['materialization_state']);
        $this->assertSame(0, DB::table('transport_reporting_obligations')->where('school_id', $f['school']->id)->count());
    }

    public function test_bounded_inputs_and_unknown_fields_are_rejected(): void
    {
        $f = $this->fixture();
        foreach ([
            ['date_from' => '2026-11-01', 'date_to' => '2026-12-02'],
            ['date_from' => '2026-11-10', 'date_to' => '2026-11-09'],
            ['limit' => 101], ['offset' => -1], ['overdue' => 'maybe'],
            ['report_state' => 'complete'], ['school_id' => $f['school']->id],
        ] as $input) {
            $this->denied(fn () => $this->visibility()->listing($f['actor'], $input), 422);
        }
    }

    public function test_listing_does_not_expose_submission_text_or_handover_identity_evidence(): void
    {
        $f = $this->dailyFixture();
        $this->recordEvent($f, 'pickup', $f['guardianGrant']['id']);
        CarbonImmutable::setTestNow('2026-11-09T06:00:00Z');
        $this->submitDaily($f);
        $before = $this->evidenceCounts($f);
        $result = $this->visibility()->listing($f['actor']);
        $this->assertSame($before, $this->evidenceCounts($f));
        $json = json_encode($result, JSON_THROW_ON_ERROR);
        foreach (['"snapshot"', '"summary":"', '"from_person"', '"to_person"', '"guardian_id"', '"phone"', '"exception_note"'] as $privateField) {
            $this->assertStringNotContainsString($privateField, $json);
        }
        $this->assertSame('submitted', $result['data'][0]['report_state']);
        $this->assertArrayNotHasKey('school_id', $result['data'][0]);
    }

    public function test_historical_unmaterialized_days_remain_explicit_coverage_gaps(): void
    {
        $f = $this->fixture();
        $this->policy($f, ['schedule_id' => $this->emptySchedule($f)]);
        CarbonImmutable::setTestNow('2026-11-10T04:00:00Z');
        $before = $this->evidenceCounts($f);
        $result = $this->visibility()->listing($f['actor'], ['date_from' => '2026-11-09', 'date_to' => '2026-11-10']);
        $this->assertSame(2, $result['meta']['total']);
        $history = array_values(array_filter($result['data'], fn ($row) => $row['service_date'] === '2026-11-09'))[0];
        $this->assertSame('historical_materialization_not_supported', $history['materialization_blocker']);
        $this->assertSame('missing', $history['report_state']);
        $this->assertTrue($history['overdue']);
        $this->assertSame('unassigned', $history['responsibility_state']);
        $this->assertSame($before, $this->evidenceCounts($f));
    }

    public function test_policy_timezone_limits_expected_dates_independently_of_school_midnight(): void
    {
        $f = $this->fixture();
        $schedule = $this->emptySchedule($f, ['timezone' => 'America/Los_Angeles']);
        $policy = $this->policy($f, ['schedule_id' => $schedule]);
        // Nairobi is already on November 10; this service is still on November 9.
        CarbonImmutable::setTestNow('2026-11-09T22:00:00Z');
        $obligation = $this->reporting()->materialize($f['actor'], $policy['id']);
        $result = $this->visibility()->listing($f['actor'], [
            'date_from' => '2026-11-09', 'date_to' => '2026-11-10',
        ]);
        $this->assertSame(1, $result['meta']['total']);
        $row = $result['data'][0];
        $this->assertSame('2026-11-09', $row['service_date']);
        $this->assertSame('America/Los_Angeles', $row['timezone']);
        $this->assertSame($obligation['id'], $row['obligation_id']);
        $this->assertTrue(CarbonImmutable::parse($row['deadline_at'])->equalTo(CarbonImmutable::parse('2026-11-10T01:00:00Z')));
        $this->assertFalse($row['deadline_passed']);
    }

    public function test_explicit_next_day_deadline_does_not_become_overdue_on_service_day(): void
    {
        $f = $this->fixture();
        $this->reporting()->materialize($f['actor'], $this->policy($f, [
            'deadline_time' => '01:00', 'deadline_day_offset' => 1,
        ])['id']);
        CarbonImmutable::setTestNow('2026-11-09T14:30:01Z');
        $filter = ['date_from' => '2026-11-09', 'date_to' => '2026-11-09'];
        $row = $this->visibility()->listing($f['actor'], $filter)['data'][0];
        $this->assertFalse($row['overdue']);
        $this->assertFalse($row['deadline_passed']);
        CarbonImmutable::setTestNow('2026-11-09T22:30:00Z');
        $this->assertFalse($this->visibility()->listing($f['actor'], $filter)['data'][0]['overdue']);
        CarbonImmutable::setTestNow('2026-11-09T22:30:01Z');
        $this->assertTrue($this->visibility()->listing($f['actor'], $filter)['data'][0]['overdue']);
    }

    public function test_historical_visibility_remains_available_for_read_only_schools(): void
    {
        $f = $this->dailyFixture();
        DB::table('schools')->where('id', $f['school']->id)->update(['lifecycle_state' => 'read_only']);
        $counts = $this->evidenceCounts($f);
        $row = $this->visibility()->listing($f['actor'])['data'][0];
        $this->assertSame($f['obligation'], $row['obligation_id']);
        $this->assertSame('missing', $row['report_state']);
        $this->assertSame($counts, $this->evidenceCounts($f));
    }

    private function visibility(): TransportReportingVisibilityService
    {
        return app(TransportReportingVisibilityService::class);
    }

    private function emptySchedule(array $f, array $extra = []): string
    {
        $original = DB::table('transport_dated_assignments')->where('id', $f['assignment']['id'])->value('schedule_id');
        $row = DB::table('transport_service_schedules')->where('id', $original)->first();
        $id = (string) Str::uuid();
        DB::table('transport_service_schedules')->insert([
            'id' => $id, 'school_id' => $f['school']->id, 'route_id' => $row->route_id,
            'service_code' => 'VIEW-'.Str::random(12), 'direction' => $row->direction,
            'timezone' => $row->timezone, 'weekday_mask' => $row->weekday_mask,
            'effective_from' => $row->effective_from, 'effective_to' => $row->effective_to,
            'departure_time' => $row->departure_time, 'completion_time' => $row->completion_time,
            'completion_day_offset' => $row->completion_day_offset,
            'created_by' => $f['actor']->id, 'updated_by' => $f['actor']->id, ...$extra,
        ]);

        return $id;
    }

    private function evidenceCounts(array $f): array
    {
        $counts = [];
        foreach (['transport_reporting_obligations', 'transport_daily_submissions', 'transport_daily_reviews', 'transport_journey_events', 'transport_exception_follow_ups', 'audit_logs'] as $table) {
            $counts[$table] = DB::table($table)->where('school_id', $f['school']->id)->count();
        }

        return $counts;
    }

    private function daily(): TransportDailyReportService
    {
        return app(TransportDailyReportService::class);
    }

    private function grant(User $actor, string $name): void
    {
        $permission = DB::table('permissions')->where('permission_name', $name)->value('id');
        $this->assertNotNull($permission);
        if (! DB::table('role_permissions')->where('role_id', $actor->role_id)->where('permission_id', $permission)->exists()) {
            DB::table('role_permissions')->insert([
                'id' => (string) Str::uuid(), 'role_id' => $actor->role_id, 'permission_id' => $permission,
            ]);
        }
    }

    private function dailyFixture(): array
    {
        $f = $this->journey();
        $this->grant($f['actor'], 'submit_transport_daily_reports');
        $f['obligation'] = $this->reporting()->materialize($f['actor'], $this->policy($f)['id'])['id'];

        return $f;
    }

    private function reviewer(array $f): User
    {
        $builder = UserBuilder::create($f['school'], RoleBuilder::create());
        $user = User::withoutGlobalScopes()->findOrFail($builder->id);
        $this->grant($user, 'view_transport_operations');
        $this->grant($user, 'review_transport_daily_reports');

        return $user;
    }

    private function submitDaily(array $f): array
    {
        $before = $this->daily()->show($f['actor'], $f['obligation']);

        return $this->daily()->submit($f['actor'], $f['obligation'], [
            'expected_version' => $before['version'],
            'summary' => 'End of service account based on recorded evidence.',
            'exception_note' => 'Outstanding operational and custody issues remain under active follow-up.',
        ]);
    }

    private function reporting(): TransportReportingObligationService
    {
        return app(TransportReportingObligationService::class);
    }

    private function policy(array $f, array $extra = []): array
    {
        $schedule = DB::table('transport_dated_assignments')
            ->where('id', $f['assignment']['id'])->value('schedule_id');

        return $this->reporting()->createPolicy($f['actor'], [
            'schedule_id' => $schedule,
            'effective_from' => '2026-11-09', 'effective_to' => '2026-11-30',
            'weekday_mask' => 127,
            'deadline_time' => '17:00', 'deadline_day_offset' => 0,
            'grace_minutes' => 30,
            ...$extra,
        ]);
    }

    private function events(): TransportJourneyEventService
    {
        return app(TransportJourneyEventService::class);
    }

    private function input(array $f, string $boundary, string $authorization): array
    {
        $current = $this->events()->show($f['actor'], $f['roster']);

        return [
            'expected_version' => $current['version'],
            'boundary' => $boundary, 'outcome' => 'recorded',
            'crew_user_id' => $f['actor']->id,
            'authorization_id' => $authorization,
            'occurred_at' => CarbonImmutable::now('UTC')->toIso8601String(),
            'verification_method' => 'known_person_in_person',
            'verification_note' => 'Actual handover counterpart verified in person.',
            'receipt_method' => 'authenticated_receiver',
        ];
    }

    private function recordEvent(
        array $f,
        string $boundary,
        string $authorization,
        ?User $actor = null,
        string $receipt = 'authenticated_receiver'
    ): array {
        return $this->events()->record($actor ?? $f['actor'], $f['roster'], [
            ...$this->input($f, $boundary, $authorization),
            'receipt_method' => $receipt,
        ]);
    }

    private function journey(string $direction = 'to_school'): array
    {
        $f = $this->fixture($direction);
        CarbonImmutable::setTestNow('2026-11-09T04:30:00Z');

        $permission = DB::table('permissions')
            ->where('permission_name', 'authorize_transport_handover')->value('id');
        $this->assertNotNull($permission);
        DB::table('role_permissions')->insert([
            'id' => (string) Str::uuid(), 'role_id' => $f['actor']->role_id,
            'permission_id' => $permission,
        ]);

        $guardian = (string) Str::uuid();
        $link = (string) Str::uuid();
        DB::table('parents')->insert([
            'id' => $guardian, 'school_id' => $f['school']->id,
            'first_name' => 'Approved', 'last_name' => 'Guardian',
            'phone' => '+254700000001', 'active' => true, 'is_deleted' => false,
        ]);
        DB::table('learner_parents')->insert([
            'id' => $link, 'learner_id' => $f['learner']->id,
            'parent_id' => $guardian, 'relationship' => 'Parent',
            'active' => true, 'is_deleted' => false,
            'can_pick_learner' => true, 'portal_enabled' => false,
        ]);

        $authorizations = app(
            TransportHandoverAuthorizationService::class
        );
        $guardianGrant = $authorizations->create($f['actor'], [
            'assignment_id' => $f['assignment']['id'],
            'kind' => 'guardian_recipient',
            'learner_id' => $f['learner']->id, 'stop_id' => $f['stop'],
            'guardian_id' => $guardian, 'guardian_link_id' => $link,
            'valid_from' => '2026-11-09', 'valid_to' => '2026-11-09',
            'reason' => 'Approved concrete-run handover arrangement.',
        ]);
        $receiver = User::withoutGlobalScopes()->findOrFail($f['unassigned']->id);
        $schoolGrant = $authorizations->create($f['actor'], [
            'assignment_id' => $f['assignment']['id'],
            'kind' => $direction === 'to_school' ? 'school_receiver' : 'school_releaser',
            'staff_user_id' => $receiver->id,
            'valid_from' => '2026-11-09', 'valid_to' => '2026-11-09',
            'reason' => 'Assigned school handover counterpart.',
        ]);
        $trip = app(TransportTripService::class)->capture($f['actor'], [
            'assignment_id' => $f['assignment']['id'],
        ]);
        $roster = $trip['roster'][0]['id'];

        return [...$f, ...compact(
            'guardian', 'link', 'guardianGrant', 'schoolGrant', 'receiver', 'roster'
        )];
    }

    private function denied(callable $operation, int $status): void
    {
        try {
            $operation();
            $this->fail('Invalid journey operation succeeded.');
        } catch (ValidationException $exception) {
            $this->assertSame(422, $status);
            $this->assertNotEmpty($exception->errors());
        } catch (HttpExceptionInterface $exception) {
            $this->assertSame($status, $exception->getStatusCode());
        }
    }

    private function fixture(string $direction = 'to_school'): array
    {
        $school = SchoolBuilder::create([
            'operating_mode' => 'day', 'lifecycle_state' => 'active',
            'timezone' => 'Africa/Nairobi',
        ]);
        TransportSubscriptionFixture::activate((string) $school->id);
        $role = RoleBuilder::create();
        $user = UserBuilder::create($school, $role);
        $actor = User::withoutGlobalScopes()->findOrFail($user->id);
        $unassigned = UserBuilder::create($school, $role);
        $grade = GradeBuilder::create($school);
        $stream = StreamBuilder::create($school, $grade);
        $learner = LearnerBuilder::create($school, $grade, $stream, [
            'mode_of_study' => 'day_scholar',
        ]);
        $other = LearnerBuilder::create($school, $grade, $stream, [
            'mode_of_study' => 'day_scholar',
        ]);

        foreach ([
            'manage_transport', 'manage_transport_enrolments',
            'view_transport_operations', 'record_transport_journeys',
        ] as $name) {
            $permission = DB::table('permissions')->where('permission_name', $name)->value('id');
            $this->assertNotNull($permission);
            DB::table('role_permissions')->insert([
                'id' => (string) Str::uuid(), 'role_id' => $role->id,
                'permission_id' => $permission,
            ]);
        }

        $route = (string) Str::uuid();
        DB::table('transport_routes')->insert([
            'id' => $route, 'school_id' => $school->id,
            'route_code' => 'R-'.Str::random(12), 'route_name' => 'Original route',
            'created_by' => $actor->id, 'updated_by' => $actor->id,
        ]);
        $stop = (string) Str::uuid();
        DB::table('transport_route_stops')->insert([
            'id' => $stop, 'school_id' => $school->id, 'route_id' => $route,
            'direction' => $direction, 'stop_name' => 'Original stop', 'stop_order' => 1,
            'created_by' => $actor->id, 'updated_by' => $actor->id,
        ]);
        $vehicle = (string) Str::uuid();
        DB::table('transport_vehicles')->insert([
            'id' => $vehicle, 'school_id' => $school->id,
            'registration_number' => 'BUS-'.Str::random(12),
            'ownership_type' => 'school_owned', 'learner_capacity' => 2,
            'created_by' => $actor->id, 'updated_by' => $actor->id,
        ]);
        $schedule = (string) Str::uuid();
        DB::table('transport_service_schedules')->insert([
            'id' => $schedule, 'school_id' => $school->id, 'route_id' => $route,
            'service_code' => 'S-'.Str::random(12), 'direction' => $direction,
            'timezone' => 'Africa/Nairobi', 'weekday_mask' => 127,
            'effective_from' => '2026-11-01', 'effective_to' => '2026-12-31',
            'departure_time' => '07:00', 'completion_time' => '08:00',
            'created_by' => $actor->id, 'updated_by' => $actor->id,
        ]);

        $assignment = app(TransportAssignmentService::class)->create($actor, [
            'schedule_id' => $schedule, 'service_date' => '2026-11-09',
            'vehicle_id' => $vehicle, 'driver_id' => $actor->id,
        ]);
        $reservation = app(TransportLearnerReservationService::class)->create($actor, [
            'assignment_id' => $assignment['id'],
            'learner_id' => $learner->id, 'stop_id' => $stop,
        ]);

        return compact(
            'school', 'actor', 'unassigned', 'learner', 'other',
            'route', 'stop', 'vehicle', 'assignment', 'reservation'
        );
    }
}
