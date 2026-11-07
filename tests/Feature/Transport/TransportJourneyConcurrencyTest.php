<?php

declare(strict_types=1);

namespace Tests\Feature\Transport;

use App\Models\User;
use App\Services\SchoolSetupReadinessService;
use App\Services\Transport\TransportAssignmentService;
use App\Services\Transport\TransportAttendanceService;
use App\Services\Transport\TransportDailyReportService;
use App\Services\Transport\TransportExceptionService;
use App\Services\Transport\TransportHandoverAuthorizationService;
use App\Services\Transport\TransportJourneyEventService;
use App\Services\Transport\TransportLearnerReservationService;
use App\Services\Transport\TransportReportingObligationService;
use App\Services\Transport\TransportTripService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\Database\AcademicYearBuilder;
use Tests\Support\Database\GradeBuilder;
use Tests\Support\Database\LearnerBuilder;
use Tests\Support\Database\RoleBuilder;
use Tests\Support\Database\SchoolBuilder;
use Tests\Support\Database\StreamBuilder;
use Tests\Support\Database\TermBuilder;
use Tests\Support\Database\UserBuilder;
use Tests\Support\TransportJourneyConcurrencyRunner;
use Tests\TestCase;

class TransportJourneyConcurrencyTest extends TestCase
{
    private array $raceFixture = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertIsolatedDatabase();
        CarbonImmutable::setTestNow('2026-11-09T04:30:00Z');
    }

    protected function tearDown(): void
    {
        try {
            if ($this->raceFixture !== []) {
                $this->removeFixture();
            }
        } finally {
            CarbonImmutable::setTestNow();
            parent::tearDown();
        }
    }

    public function test_competing_reporting_schedulers_create_one_obligation_and_one_audit(): void
    {
        $f = $this->reportingSchedulerRaceFixture();
        $payload = $this->reportingSchedulerPayload($f);
        $result = $this->race($payload, $payload);
        $this->assertSame('finished', $result['first']['event']);
        $this->assertSame('finished', $result['second']['event']);
        $this->assertSame(1, $result['first']['result']['created']);
        $this->assertSame(0, $result['second']['result']['created']);
        $this->assertSame(1, $result['second']['result']['existing']);
        $this->assertSame(0, $result['second']['result']['failed']);
        $this->assertSame($result['first']['result']['id'], $result['second']['result']['id']);
        $this->assertSame(1, DB::table('transport_reporting_obligations')->where('school_id', $f['school'])->count());
        $this->assertSame(1, DB::table('audit_logs')->where('school_id', $f['school'])->where('table_name', 'transport_reporting_obligations')->count());
    }

    public function test_waiting_reporting_scheduler_creates_obligation_after_first_rolls_back(): void
    {
        $f = $this->reportingSchedulerRaceFixture();
        $first = $this->reportingSchedulerPayload($f);
        $first['rollback'] = true;
        $result = $this->race($first, $this->reportingSchedulerPayload($f));
        $this->assertSame('finished', $result['first']['event']);
        $this->assertTrue($result['first']['result']['rolled_back']);
        $this->assertSame('finished', $result['second']['event']);
        $this->assertSame(1, $result['second']['result']['created']);
        $this->assertSame(0, $result['second']['result']['existing']);
        $this->assertSame(1, DB::table('transport_reporting_obligations')->where('school_id', $f['school'])->count());
        $this->assertSame(1, DB::table('audit_logs')->where('school_id', $f['school'])->where('table_name', 'transport_reporting_obligations')->count());
        $this->assertDatabaseMissing('transport_reporting_obligations', ['id' => $result['first']['result']['id']]);
    }

    private function reportingSchedulerRaceFixture(): array
    {
        $f = $this->journeyFixture();
        $actor = User::withoutGlobalScopes()->findOrFail($f['actor']);
        app(TransportReportingObligationService::class)->createPolicy($actor, [
            'schedule_id' => $f['schedule'], 'effective_from' => '2026-11-09',
            'effective_to' => '2026-11-30', 'weekday_mask' => 127,
            'deadline_time' => '17:00', 'deadline_day_offset' => 0, 'grace_minutes' => 30,
        ]);

        return $f;
    }

    private function reportingSchedulerPayload(array $f): array
    {
        return [
            'operation' => 'reporting_scheduler', 'school_id' => $f['school'], 'actor_id' => $f['actor'],
        ];
    }

    public function test_competing_daily_submissions_commit_one_snapshot_and_audit(): void
    {
        $f = $this->dailyRaceFixture();
        $payload = $this->dailySubmitPayload($f);
        $result = $this->race($payload, $payload);
        $this->assertSame('finished', $result['first']['event']);
        $this->assertSame('rejected', $result['second']['event']);
        $this->assertSame(409, $result['second']['http_status']);
        $this->assertSame(1, DB::table('transport_daily_submissions')->where('school_id', $f['school'])->count());
        $this->assertSame(1, DB::table('audit_logs')->where('school_id', $f['school'])->where('table_name', 'transport_daily_submissions')->count());
    }

    public function test_waiting_daily_submission_succeeds_after_first_rolls_back(): void
    {
        $f = $this->dailyRaceFixture();
        $first = $this->dailySubmitPayload($f);
        $first['rollback'] = true;
        $result = $this->race($first, $this->dailySubmitPayload($f));
        $this->assertSame('finished', $result['second']['event']);
        $this->assertTrue($result['first']['result']['rolled_back']);
        $this->assertSame(1, DB::table('transport_daily_submissions')->where('school_id', $f['school'])->count());
        $this->assertDatabaseMissing('transport_daily_submissions', ['id' => $result['first']['result']['id']]);
    }

    public function test_competing_daily_reviews_commit_one_decision_and_audit(): void
    {
        $f = $this->dailyRaceFixture(true);
        $payload = $this->dailyReviewPayload($f);
        $result = $this->race($payload, $payload);
        $this->assertSame('finished', $result['first']['event']);
        $this->assertSame('rejected', $result['second']['event']);
        $this->assertSame(409, $result['second']['http_status']);
        $this->assertSame(1, DB::table('transport_daily_reviews')->where('school_id', $f['school'])->count());
        $this->assertSame(1, DB::table('audit_logs')->where('school_id', $f['school'])->where('table_name', 'transport_daily_reviews')->count());
    }

    public function test_waiting_daily_review_succeeds_after_first_rolls_back(): void
    {
        $f = $this->dailyRaceFixture(true);
        $first = $this->dailyReviewPayload($f);
        $first['rollback'] = true;
        $result = $this->race($first, $this->dailyReviewPayload($f));
        $this->assertSame('finished', $result['second']['event']);
        $this->assertTrue($result['first']['result']['rolled_back']);
        $this->assertSame(1, DB::table('transport_daily_reviews')->where('school_id', $f['school'])->count());
    }

    public function test_database_journey_change_rejects_waiting_stale_daily_snapshot(): void
    {
        $f = $this->dailyRaceFixture();
        $result = $this->race($this->databaseEventPayload($f), $this->dailySubmitPayload($f));
        $this->assertSame('finished', $result['first']['event']);
        $this->assertSame('rejected', $result['second']['event']);
        $this->assertSame(409, $result['second']['http_status']);
        $this->assertSame(0, DB::table('transport_daily_submissions')->where('school_id', $f['school'])->count());
    }

    public function test_daily_snapshot_stays_frozen_before_waiting_database_journey_change(): void
    {
        $f = $this->dailyRaceFixture();
        $result = $this->race($this->dailySubmitPayload($f), $this->databaseEventPayload($f));
        $this->assertSame('finished', $result['first']['event']);
        $this->assertSame('finished', $result['second']['event']);
        $saved = json_decode(DB::table('transport_daily_submissions')->where('school_id', $f['school'])->value('snapshot'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(0, $saved['totals']['on_board']);
        $this->assertSame(1, $saved['totals']['awaiting_outcome']);
        $actor = User::withoutGlobalScopes()->findOrFail($f['driver_a']);
        $now = app(TransportDailyReportService::class)->show($actor, $f['obligation_id']);
        $this->assertSame(1, $now['current_snapshot']['totals']['on_board']);
        $this->assertSame($saved, $now['submissions'][0]['snapshot']);
    }

    private function dailyRaceFixture(bool $submit = false): array
    {
        $f = $this->journeyFixture();
        foreach (['submit_transport_daily_reports', 'review_transport_daily_reports'] as $name) {
            $id = DB::table('permissions')->where('permission_name', $name)->value('id');
            $this->assertNotNull($id);
            DB::table('role_permissions')->insert([
                'id' => (string) Str::uuid(), 'role_id' => $f['role'], 'permission_id' => $id,
            ]);
        }
        $actor = User::withoutGlobalScopes()->findOrFail($f['actor']);
        $policyService = app(TransportReportingObligationService::class);
        $policy = $policyService->createPolicy($actor, [
            'schedule_id' => $f['schedule'], 'effective_from' => '2026-11-09',
            'effective_to' => '2026-11-30', 'weekday_mask' => 127,
            'deadline_time' => '17:00', 'deadline_day_offset' => 0, 'grace_minutes' => 30,
        ]);
        $f['obligation_id'] = $policyService->materialize($actor, $policy['id'])['id'];
        $driver = User::withoutGlobalScopes()->findOrFail($f['driver_a']);
        $reports = app(TransportDailyReportService::class);
        $current = $reports->show($driver, $f['obligation_id']);
        $f['daily_version'] = $current['version'];
        if ($submit) {
            CarbonImmutable::setTestNow('2026-11-09T06:00:00Z');
            $submitted = $reports->submit($driver, $f['obligation_id'], $this->dailySubmitPayload($f)['input']);
            $f['submission'] = $submitted['submissions'][0];
        }

        return $f;
    }

    private function dailySubmitPayload(array $f): array
    {
        return [
            'operation' => 'daily_submit', 'school_id' => $f['school'],
            'actor_id' => $f['driver_a'], 'obligation_id' => $f['obligation_id'],
            'input' => [
                'expected_version' => $f['daily_version'],
                'summary' => 'End of service account under actual contention.',
                'exception_note' => 'Outstanding operational issues remain under explicit follow-up.',
            ],
        ];
    }

    private function dailyReviewPayload(array $f): array
    {
        return [
            'operation' => 'daily_review', 'school_id' => $f['school'],
            'actor_id' => $f['actor'], 'submission_id' => $f['submission']['id'],
            'input' => [
                'expected_version' => $f['submission']['review_version'],
                'decision' => 'changes_requested',
                'note' => 'Independent review requests a clearer service account.',
            ],
        ];
    }

    public function test_competing_events_commit_one_event_and_one_audit(): void
    {
        $f = $this->journeyFixture();
        $payload = $this->eventPayload($f);
        $result = $this->race($payload, $payload);

        $this->assertSame('finished', $result['first']['event']);
        $this->assertSame('rejected', $result['second']['event']);
        $this->assertSame(409, $result['second']['http_status']);
        $this->assertEventCount($f, 1);
        $this->assertSame(1, $this->eventAuditCount($f));
        $this->assertSame(0, $this->revocationCount($f));
    }

    public function test_waiting_event_succeeds_when_first_event_rolls_back(): void
    {
        $f = $this->journeyFixture();
        $first = $this->eventPayload($f);
        $first['rollback'] = true;
        $result = $this->race($first, $this->eventPayload($f));

        $this->assertSame('finished', $result['first']['event']);
        $this->assertTrue($result['first']['result']['rolled_back']);
        $this->assertSame('finished', $result['second']['event']);
        $this->assertEventCount($f, 1);
        $this->assertSame(1, $this->eventAuditCount($f));
        $this->assertDatabaseMissing('transport_journey_events', [
            'id' => $result['first']['result']['id'],
        ]);
    }

    public function test_committed_revocation_blocks_waiting_service_event(): void
    {
        $f = $this->journeyFixture();
        $result = $this->race(
            $this->revokePayload($f),
            $this->eventPayload($f)
        );

        $this->assertSame('finished', $result['first']['event']);
        $this->assertSame('rejected', $result['second']['event']);
        $this->assertSame(422, $result['second']['http_status']);
        $this->assertEventCount($f, 0);
        $this->assertSame(0, $this->eventAuditCount($f));
        $this->assertSame(1, $this->revocationCount($f));
    }

    public function test_committed_event_retains_evidence_when_waiting_revocation_commits(): void
    {
        $f = $this->journeyFixture();
        $result = $this->race(
            $this->eventPayload($f),
            $this->revokePayload($f)
        );

        $this->assertSame('finished', $result['first']['event']);
        $this->assertSame('finished', $result['second']['event']);
        $this->assertEventCount($f, 1);
        $this->assertSame(1, $this->eventAuditCount($f));
        $this->assertSame(1, $this->revocationCount($f));
        $this->assertPreservedSnapshot($f);
    }

    public function test_database_revocation_lock_blocks_event_without_a_school_service_lock(): void
    {
        $f = $this->journeyFixture();
        $result = $this->race(
            $this->databaseRevokePayload($f),
            $this->databaseEventPayload($f)
        );

        $this->assertSame('finished', $result['first']['event']);
        $this->assertSame('rejected', $result['second']['event']);
        $this->assertSame('23514', $result['second']['sqlstate']);
        $this->assertEventCount($f, 0);
        $this->assertSame(1, $this->revocationCount($f));
    }

    public function test_database_event_holds_authorization_until_commit_before_revocation(): void
    {
        $f = $this->journeyFixture();
        $result = $this->race(
            $this->databaseEventPayload($f),
            $this->databaseRevokePayload($f)
        );

        $this->assertSame('finished', $result['first']['event']);
        $this->assertSame('finished', $result['second']['event']);
        $this->assertEventCount($f, 1);
        $this->assertSame(1, $this->revocationCount($f));
        $this->assertPreservedSnapshot($f);
    }

    public function test_rolled_back_database_revocation_allows_waiting_event(): void
    {
        $f = $this->journeyFixture();
        $first = $this->databaseRevokePayload($f);
        $first['rollback'] = true;
        $result = $this->race($first, $this->databaseEventPayload($f));

        $this->assertSame('finished', $result['first']['event']);
        $this->assertTrue($result['first']['result']['rolled_back']);
        $this->assertSame('finished', $result['second']['event']);
        $this->assertEventCount($f, 1);
        $this->assertSame(0, $this->revocationCount($f));
        $this->assertPreservedSnapshot($f);
    }

    public function test_competing_exception_resolutions_commit_one_resolution_and_one_audit(): void
    {
        $f = $this->exceptionFixture(true);
        $payload = $this->exceptionPayload($f, 'resolve');
        $result = $this->race($payload, $payload);

        $this->assertSame('finished', $result['first']['event']);
        $this->assertSame('rejected', $result['second']['event']);
        $this->assertSame(409, $result['second']['http_status']);
        $this->assertSame(1, $this->exceptionRowCount($f, 'resolve'));
        $this->assertSame(1, $this->exceptionAuditCount($f, 'resolve'));
        $this->assertFailurePreserved($f);

        $driver = User::withoutGlobalScopes()->findOrFail($f['driver_a']);
        $journey = app(TransportJourneyEventService::class)
            ->show($driver, $f['roster']);
        $this->assertNotContains($f['failure'], $journey['unresolved_failure_ids']);
        $this->assertSame('on_board', $journey['state']);
    }

    public function test_waiting_resolution_succeeds_after_first_resolution_rolls_back(): void
    {
        $f = $this->exceptionFixture(true);
        $first = $this->exceptionPayload($f, 'resolve');
        $first['rollback'] = true;

        $result = $this->race($first, $this->exceptionPayload($f, 'resolve'));

        $this->assertSame('finished', $result['first']['event']);
        $this->assertTrue($result['first']['result']['rolled_back']);
        $this->assertSame('finished', $result['second']['event']);
        $this->assertSame(1, $this->exceptionRowCount($f, 'resolve'));
        $this->assertSame(1, $this->exceptionAuditCount($f, 'resolve'));
        $this->assertDatabaseMissing('transport_exception_follow_ups', [
            'id' => $result['first']['result']['id'],
        ]);
        $this->assertFailurePreserved($f);
    }

    public function test_committed_journey_change_rejects_waiting_follow_up_with_stale_custody(): void
    {
        $f = $this->exceptionFixture();
        $result = $this->race(
            $this->exceptionJourneyPayload($f),
            $this->exceptionPayload($f, 'follow_up')
        );

        $this->assertSame('finished', $result['first']['event']);
        $this->assertSame('rejected', $result['second']['event']);
        $this->assertSame(409, $result['second']['http_status']);
        $this->assertSame(0, $this->exceptionRowCount($f, 'follow_up'));
        $this->assertSame(0, $this->exceptionAuditCount($f, 'follow_up'));
        $this->assertFailurePreserved($f);
    }

    public function test_follow_up_committed_before_journey_change_retains_its_custody_snapshot(): void
    {
        $f = $this->exceptionFixture();
        $result = $this->race(
            $this->exceptionPayload($f, 'follow_up'),
            $this->exceptionJourneyPayload($f)
        );

        $this->assertSame('finished', $result['first']['event']);
        $this->assertSame('finished', $result['second']['event']);
        $this->assertSame(1, $this->exceptionRowCount($f, 'follow_up'));
        $this->assertSame(1, $this->exceptionAuditCount($f, 'follow_up'));
        $this->assertOldSnapshotAndCurrentCustody($f);
    }

    public function test_database_competing_resolutions_reject_stale_exception_history(): void
    {
        $f = $this->exceptionFixture(true);
        $result = $this->race(
            $this->databaseExceptionPayload($f, 'resolve'),
            $this->databaseExceptionPayload($f, 'resolve')
        );

        $this->assertSame('finished', $result['first']['event']);
        $this->assertSame('rejected', $result['second']['event']);
        $this->assertSame('23P01', $result['second']['sqlstate']);
        $this->assertSame(1, $this->exceptionRowCount($f, 'resolve'));
        $this->assertFailurePreserved($f);
    }

    public function test_database_journey_change_rejects_waiting_follow_up_basis(): void
    {
        $f = $this->exceptionFixture();
        $result = $this->race(
            $this->exceptionJourneyPayload($f, true),
            $this->databaseExceptionPayload($f, 'follow_up')
        );

        $this->assertSame('finished', $result['first']['event']);
        $this->assertSame('rejected', $result['second']['event']);
        $this->assertSame('23P01', $result['second']['sqlstate']);
        $this->assertSame(0, $this->exceptionRowCount($f, 'follow_up'));
        $this->assertFailurePreserved($f);
    }

    public function test_database_follow_up_locks_custody_until_snapshot_is_committed(): void
    {
        $f = $this->exceptionFixture();
        $result = $this->race(
            $this->databaseExceptionPayload($f, 'follow_up'),
            $this->exceptionJourneyPayload($f, true)
        );

        $this->assertSame('finished', $result['first']['event']);
        $this->assertSame('finished', $result['second']['event']);
        $this->assertSame(1, $this->exceptionRowCount($f, 'follow_up'));
        $this->assertOldSnapshotAndCurrentCustody($f);
    }

    private function exceptionFixture(bool $completePickup = false): array
    {
        $f = $this->journeyFixture();

        return DB::transaction(function () use ($f, $completePickup): array {
            $driver = User::withoutGlobalScopes()->findOrFail($f['driver_a']);
            $actor = User::withoutGlobalScopes()->findOrFail($f['actor']);
            $events = app(TransportJourneyEventService::class);

            $failed = $events->record($driver, $f['roster'], [
                'expected_version' => $f['journey_version'],
                'boundary' => 'pickup',
                'outcome' => 'failed',
                'crew_user_id' => $f['driver_a'],
                'occurred_at' => '2026-11-09T04:30:00Z',
                'receipt_method' => 'none',
                'failure_reason' => 'person_unavailable',
                'follow_up' => 'Learner has not entered transport custody; contact school.',
            ]);

            $failure = $failed['events'][0]['id'];
            $current = $failed;
            $proof = null;

            if ($completePickup) {
                $input = $this->eventPayload($f)['input'];
                $input['expected_version'] = $failed['version'];
                $current = $events->record($driver, $f['roster'], $input);
                $proof = $current['events'][1]['id'];
            }

            $rows = $current['events'];
            $last = end($rows);
            $exception = app(TransportExceptionService::class)
                ->show($actor, $failure);

            return [
                ...$f,
                'failure' => $failure,
                'proof' => $proof,
                'basis_event' => $last['id'],
                'journey_version' => $current['version'],
                'exception_version' => $exception['version'],
                'original_failure' => DB::table('transport_journey_events')
                    ->where('id', $failure)->first(),
            ];
        });
    }

    private function exceptionPayload(array $f, string $action): array
    {
        $input = [
            'expected_version' => $f['exception_version'],
            'expected_journey_version' => $f['journey_version'],
            'action' => $action,
            'note' => 'Explicit custody follow-up under verified lock contention.',
        ];

        if ($action === 'resolve') {
            $input['resolution_event_id'] = $f['proof'];
        }

        return [
            'operation' => 'exception_action',
            'school_id' => $f['school'],
            'actor_id' => $f['actor'],
            'failure_id' => $f['failure'],
            'input' => $input,
        ];
    }

    private function databaseExceptionPayload(array $f, string $action): array
    {
        return [
            'operation' => 'exception_database',
            'school_id' => $f['school'],
            'row' => [
                'id' => (string) Str::uuid(),
                'school_id' => $f['school'],
                'failure_event_id' => $f['failure'],
                'previous_id' => null,
                'basis_event_id' => $f['basis_event'],
                'action' => $action,
                'note' => 'Database exception contender.',
                'assigned_to' => $f['driver_a'],
                'resolution_event_id' => $action === 'resolve' ? $f['proof'] : null,
                'recorded_by' => $f['actor'],
            ],
        ];
    }

    private function exceptionJourneyPayload(array $f, bool $database = false): array
    {
        if (! $database) {
            return $this->eventPayload($f);
        }

        $payload = $this->databaseEventPayload($f);
        $payload['row']['previous_id'] = $f['basis_event'];

        return $payload;
    }

    private function exceptionRowCount(array $f, string $action): int
    {
        return DB::table('transport_exception_follow_ups')
            ->where('school_id', $f['school'])
            ->where('failure_event_id', $f['failure'])
            ->where('action', $action)->count();
    }

    private function exceptionAuditCount(array $f, string $action): int
    {
        return DB::table('audit_logs')
            ->where('school_id', $f['school'])
            ->where('module', 'Transport')
            ->where('table_name', 'transport_exception_follow_ups')
            ->where('action', $action)->count();
    }

    private function assertFailurePreserved(array $f): void
    {
        $this->assertEquals(
            $f['original_failure'],
            DB::table('transport_journey_events')->where('id', $f['failure'])->first()
        );
    }

    private function assertOldSnapshotAndCurrentCustody(array $f): void
    {
        $row = DB::table('transport_exception_follow_ups')
            ->where('school_id', $f['school'])
            ->where('failure_event_id', $f['failure'])->first();
        $this->assertNotNull($row);
        $snapshot = json_decode($row->custody_snapshot, true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame($f['failure'], $snapshot['basis_event_id']);
        $this->assertSame('expected', $snapshot['state']);
        $this->assertNull($snapshot['custody_user_id']);
        $this->assertFalse($snapshot['custodian_identified']);

        $actor = User::withoutGlobalScopes()->findOrFail($f['actor']);
        $current = app(TransportExceptionService::class)
            ->show($actor, $f['failure']);
        $this->assertSame('open', $current['status']);
        $this->assertSame('on_board', $current['current_custody']['state']);
        $this->assertSame($f['driver_a'], $current['current_custody']['custody_user_id']);
        $this->assertFailurePreserved($f);
    }

    public function test_competing_attendance_outcomes_commit_one_outcome_and_one_audit(): void
    {
        $f = $this->attendanceRaceFixture();
        $payload = $this->attendanceOutcomePayload($f);
        $result = $this->race($payload, $payload);

        $this->assertSame('finished', $result['first']['event']);
        $this->assertSame('rejected', $result['second']['event']);
        $this->assertSame(409, $result['second']['http_status']);
        $this->assertSame(1, $this->attendanceOutcomeCount($f));
        $this->assertSame(1, $this->attendanceAuditCount($f, 'transport_journey_outcomes'));
        $this->assertSame(0, $this->attendanceCompletionCount($f));
    }

    public function test_database_attendance_outcome_blocks_waiting_pickup(): void
    {
        $f = $this->attendanceRaceFixture();
        $result = $this->race(
            $this->attendanceOutcomePayload($f, true),
            $this->databaseEventPayload($f)
        );

        $this->assertSame('finished', $result['first']['event']);
        $this->assertSame('rejected', $result['second']['event']);
        $this->assertSame('23P01', $result['second']['sqlstate']);
        $this->assertSame(1, $this->attendanceOutcomeCount($f));
        $this->assertSame(0, DB::table('transport_journey_events')
            ->where('roster_id', $f['roster'])->count());
    }

    public function test_database_pickup_blocks_waiting_attendance_cancellation(): void
    {
        $f = $this->attendanceRaceFixture();
        $result = $this->race(
            $this->databaseEventPayload($f),
            $this->attendanceOutcomePayload($f, true)
        );

        $this->assertSame('finished', $result['first']['event']);
        $this->assertSame('rejected', $result['second']['event']);
        $this->assertSame('23P01', $result['second']['sqlstate']);
        $this->assertSame(0, $this->attendanceOutcomeCount($f));
        $this->assertEventCount($f, 1);
    }

    public function test_competing_completion_requests_commit_one_completion_and_one_audit(): void
    {
        $f = $this->attendanceRaceFixture(true);
        $payload = $this->attendanceCompletionPayload($f);
        $result = $this->race($payload, $payload);

        $this->assertSame('finished', $result['first']['event']);
        $this->assertSame('rejected', $result['second']['event']);
        $this->assertSame(409, $result['second']['http_status']);
        $this->assertSame(1, $this->attendanceCompletionCount($f));
        $this->assertSame(1, $this->attendanceAuditCount($f, 'transport_trip_completions'));
        $this->assertDatabaseHas('transport_trip_completions', [
            'trip_id' => $f['trip_id'], 'status' => 'completed',
        ]);
    }

    public function test_waiting_completion_succeeds_after_first_completion_rolls_back(): void
    {
        $f = $this->attendanceRaceFixture(true);
        $first = $this->attendanceCompletionPayload($f);
        $first['rollback'] = true;
        $result = $this->race($first, $this->attendanceCompletionPayload($f));

        $this->assertSame('finished', $result['first']['event']);
        $this->assertTrue($result['first']['result']['rolled_back']);
        $this->assertSame('finished', $result['second']['event']);
        $this->assertSame(1, $this->attendanceCompletionCount($f));
        $this->assertSame(1, $this->attendanceAuditCount($f, 'transport_trip_completions'));
        $this->assertDatabaseMissing('transport_trip_completions', [
            'id' => $result['first']['result']['id'],
        ]);
    }

    public function test_database_incomplete_completion_preserves_snapshot_and_allows_waiting_pickup(): void
    {
        $f = $this->attendanceRaceFixture();
        $result = $this->race(
            $this->attendanceCompletionPayload($f, true),
            $this->databaseEventPayload($f)
        );

        $this->assertSame('finished', $result['first']['event']);
        $this->assertSame('finished', $result['second']['event']);
        $this->assertSame(1, $this->attendanceCompletionCount($f));

        $row = DB::table('transport_trip_completions')
            ->where('trip_id', $f['trip_id'])->first();
        $snapshot = json_decode($row->snapshot, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('incomplete', $row->status);
        $this->assertSame(1, $snapshot['awaiting_outcome']);
        $this->assertSame(0, $snapshot['on_board']);

        $current = $this->attendanceRaceRead($f);
        $this->assertSame(0, $current['attendance']['awaiting_outcome']);
        $this->assertSame(1, $current['attendance']['on_board']);
        $this->assertSame(
            $snapshot,
            $current['completion_history'][0]['snapshot']
        );
    }

    public function test_database_pickup_rejects_waiting_completion_with_stale_snapshot(): void
    {
        $f = $this->attendanceRaceFixture();
        $result = $this->race(
            $this->databaseEventPayload($f),
            $this->attendanceCompletionPayload($f, true)
        );

        $this->assertSame('finished', $result['first']['event']);
        $this->assertSame('rejected', $result['second']['event']);
        $this->assertSame('23P01', $result['second']['sqlstate']);
        $this->assertSame(0, $this->attendanceCompletionCount($f));
        $this->assertSame(1, $this->attendanceRaceRead($f)['attendance']['on_board']);
    }

    public function test_database_competing_completed_snapshots_commit_only_once(): void
    {
        $f = $this->attendanceRaceFixture(true);
        $result = $this->race(
            $this->attendanceCompletionPayload($f, true),
            $this->attendanceCompletionPayload($f, true)
        );

        $this->assertSame('finished', $result['first']['event']);
        $this->assertSame('rejected', $result['second']['event']);
        $this->assertSame('23P01', $result['second']['sqlstate']);
        $this->assertSame(1, $this->attendanceCompletionCount($f));
        $this->assertDatabaseHas('transport_trip_completions', [
            'trip_id' => $f['trip_id'], 'status' => 'completed',
        ]);
    }

    public function test_attendance_change_rejects_waiting_service_completion_version(): void
    {
        $f = $this->attendanceRaceFixture();
        $result = $this->race(
            $this->attendanceOutcomePayload($f),
            $this->attendanceCompletionPayload($f)
        );

        $this->assertSame('finished', $result['first']['event']);
        $this->assertSame('rejected', $result['second']['event']);
        $this->assertSame(409, $result['second']['http_status']);
        $this->assertSame(1, $this->attendanceOutcomeCount($f));
        $this->assertSame(0, $this->attendanceCompletionCount($f));
        $this->assertSame(0, $this->attendanceAuditCount($f, 'transport_trip_completions'));
    }

    private function attendanceRaceFixture(bool $handedOver = false): array
    {
        $f = $this->journeyFixture();

        return DB::transaction(function () use ($f, $handedOver): array {
            $driver = User::withoutGlobalScopes()->findOrFail($f['driver_a']);
            $actor = User::withoutGlobalScopes()->findOrFail($f['actor']);
            $tripId = DB::table('transport_trip_roster')
                ->where('school_id', $f['school'])->where('id', $f['roster'])
                ->value('trip_id');
            $this->assertNotNull($tripId);

            if ($handedOver) {
                $trip = DB::table('transport_trips')
                    ->where('school_id', $f['school'])->where('id', $tripId)->first();
                $schoolGrant = app(
                    TransportHandoverAuthorizationService::class
                )->create($actor, [
                    'assignment_id' => $trip->assignment_id,
                    'kind' => 'school_receiver',
                    'staff_user_id' => $f['driver_b'],
                    'valid_from' => '2026-11-09',
                    'valid_to' => '2026-11-09',
                    'reason' => 'School receiver for completion contention fixture.',
                ]);

                $events = app(TransportJourneyEventService::class);
                $picked = $events->record(
                    $driver, $f['roster'], $this->eventPayload($f)['input']
                );
                $arrival = $this->eventPayload($f)['input'];
                $arrival['expected_version'] = $picked['version'];
                $arrival['boundary'] = 'school_arrival';
                $arrival['authorization_id'] = $schoolGrant['id'];
                $receiver = User::withoutGlobalScopes()->findOrFail($f['driver_b']);
                $events->record($receiver, $f['roster'], $arrival);
            }

            $attendance = app(TransportAttendanceService::class)
                ->showTrip($driver, $tripId);

            return [
                ...$f,
                'trip_id' => $tripId,
                'completion_version' => $attendance['version'],
                'completion_snapshot' => $attendance['attendance'],
            ];
        });
    }

    private function attendanceOutcomePayload(array $f, bool $database = false): array
    {
        if ($database) {
            return [
                'operation' => 'attendance_outcome_database',
                'school_id' => $f['school'],
                'row' => [
                    'id' => (string) Str::uuid(),
                    'school_id' => $f['school'],
                    'roster_id' => $f['roster'],
                    'outcome' => 'cancelled',
                    'reason' => 'Cancellation contender before boarding.',
                    'recorded_by' => $f['driver_a'],
                ],
            ];
        }

        return [
            'operation' => 'attendance_outcome',
            'school_id' => $f['school'],
            'actor_id' => $f['driver_a'],
            'roster_id' => $f['roster'],
            'input' => [
                'expected_version' => $f['journey_version'],
                'outcome' => 'cancelled',
                'reason' => 'Cancellation contender before boarding.',
            ],
        ];
    }

    private function attendanceCompletionPayload(array $f, bool $database = false): array
    {
        if ($database) {
            return [
                'operation' => 'attendance_completion_database',
                'school_id' => $f['school'],
                'row' => [
                    'id' => (string) Str::uuid(),
                    'school_id' => $f['school'],
                    'trip_id' => $f['trip_id'],
                    'previous_id' => null,
                    'reason' => 'Database completion contender.',
                    'snapshot' => json_encode($f['completion_snapshot'], JSON_THROW_ON_ERROR),
                    'recorded_by' => $f['driver_a'],
                ],
            ];
        }

        return [
            'operation' => 'attendance_completion',
            'school_id' => $f['school'],
            'actor_id' => $f['driver_a'],
            'trip_id' => $f['trip_id'],
            'input' => [
                'expected_version' => $f['completion_version'],
                'reason' => 'Crew completion contender.',
            ],
        ];
    }

    private function attendanceOutcomeCount(array $f): int
    {
        return DB::table('transport_journey_outcomes')
            ->where('school_id', $f['school'])->where('roster_id', $f['roster'])->count();
    }

    private function attendanceCompletionCount(array $f): int
    {
        return DB::table('transport_trip_completions')
            ->where('school_id', $f['school'])->where('trip_id', $f['trip_id'])->count();
    }

    private function attendanceAuditCount(array $f, string $table): int
    {
        return DB::table('audit_logs')->where('school_id', $f['school'])
            ->where('module', 'Transport')->where('table_name', $table)->count();
    }

    private function attendanceRaceRead(array $f): array
    {
        $driver = User::withoutGlobalScopes()->findOrFail($f['driver_a']);

        return app(TransportAttendanceService::class)
            ->showTrip($driver, $f['trip_id']);
    }

    private function race(array $first, array $second): array
    {
        $this->assertIsolatedDatabase();
        $result = (new TransportJourneyConcurrencyRunner)
            ->run($first, $second);
        $this->assertTrue($result['contention_verified']);

        return $result;
    }

    private function eventPayload(array $f): array
    {
        return [
            'operation' => 'journey_event',
            'school_id' => $f['school'],
            'actor_id' => $f['driver_a'],
            'roster_id' => $f['roster'],
            'input' => [
                'expected_version' => $f['journey_version'],
                'boundary' => 'pickup',
                'outcome' => 'recorded',
                'crew_user_id' => $f['driver_a'],
                'authorization_id' => $f['grant']['id'],
                'occurred_at' => '2026-11-09T04:30:00Z',
                'verification_method' => 'known_person_in_person',
                'verification_note' => 'Releasing guardian verified in person.',
                'receipt_method' => 'authenticated_receiver',
            ],
        ];
    }

    private function revokePayload(array $f): array
    {
        return [
            'operation' => 'journey_revoke',
            'school_id' => $f['school'],
            'actor_id' => $f['actor'],
            'authorization_id' => $f['grant']['id'],
            'input' => [
                'expected_version' => $f['grant']['version'],
                'reason' => 'Collection authority withdrawn during contention test.',
            ],
        ];
    }

    private function databaseEventPayload(array $f): array
    {
        return [
            'operation' => 'journey_database',
            'school_id' => $f['school'],
            'row' => [
                'id' => (string) Str::uuid(),
                'school_id' => $f['school'],
                'roster_id' => $f['roster'],
                'previous_id' => null,
                'boundary' => 'pickup',
                'outcome' => 'recorded',
                'crew_user_id' => $f['driver_a'],
                'authorization_id' => $f['grant']['id'],
                'occurred_at' => '2026-11-09T04:30:00Z',
                'verification_method' => 'known_person_in_person',
                'verification_note' => 'Database contender verified releasing guardian.',
                'receipt_method' => 'authenticated_receiver',
                'recorded_by' => $f['driver_a'],
            ],
        ];
    }

    private function databaseRevokePayload(array $f): array
    {
        return [
            'operation' => 'journey_revoke_database',
            'school_id' => $f['school'],
            'row' => [
                'id' => (string) Str::uuid(),
                'school_id' => $f['school'],
                'authorization_id' => $f['grant']['id'],
                'reason' => 'Database revocation contender.',
                'revoked_by' => $f['actor'],
            ],
        ];
    }

    private function assertEventCount(array $f, int $expected): void
    {
        $this->assertSame(
            $expected,
            DB::table('transport_journey_events')
                ->where('school_id', $f['school'])
                ->where('roster_id', $f['roster'])->count()
        );

        if ($expected === 1) {
            $event = DB::table('transport_journey_events')
                ->where('roster_id', $f['roster'])->first();
            $this->assertSame(1, (int) $event->sequence);
            $this->assertNull($event->previous_id);
            $this->assertSame('on_board', $event->state_after);
            $this->assertSame($f['driver_a'], $event->custody_user_id);
        }
    }

    private function eventAuditCount(array $f): int
    {
        return DB::table('audit_logs')
            ->where('school_id', $f['school'])
            ->where('module', 'Transport')
            ->where('table_name', 'transport_journey_events')
            ->where('action', 'record')->count();
    }

    private function revocationCount(array $f): int
    {
        return DB::table('transport_handover_revocations')
            ->where('school_id', $f['school'])
            ->where('authorization_id', $f['grant']['id'])->count();
    }

    private function assertPreservedSnapshot(array $f): void
    {
        $event = DB::table('transport_journey_events')
            ->where('school_id', $f['school'])
            ->where('roster_id', $f['roster'])->first();
        $this->assertNotNull($event);
        $snapshot = json_decode(
            $event->authorization_snapshot, true, 512, JSON_THROW_ON_ERROR
        );
        $this->assertSame($f['grant']['id'], $snapshot['id']);
        $this->assertSame($f['guardian'], $snapshot['identity']['guardian_id']);
        $this->assertTrue($snapshot['used_for_success']);
        $this->assertSame($f['driver_a'], $event->confirmed_by);
    }

    private function journeyFixture(): array
    {
        $f = $this->fixture();

        return DB::transaction(function () use ($f): array {
            foreach ([
                'manage_transport_enrolments',
                'view_transport_operations',
                'record_transport_journeys',
                'authorize_transport_handover',
            ] as $name) {
                $permission = DB::table('permissions')
                    ->where('permission_name', $name)->value('id');
                $this->assertNotNull($permission);

                if (! DB::table('role_permissions')->where('role_id', $f['role'])
                    ->where('permission_id', $permission)->exists()) {
                    DB::table('role_permissions')->insert([
                        'id' => (string) Str::uuid(),
                        'role_id' => $f['role'], 'permission_id' => $permission,
                    ]);
                }
            }

            $school = DB::table('schools')->where('id', $f['school'])->first();
            $grade = DB::table('grades')->where('school_id', $f['school'])->first();
            $this->assertNotNull($grade);
            $stream = DB::table('streams')->where('school_id', $f['school'])
                ->where('grade_id', $grade->id)->first();
            $this->assertNotNull($stream);

            $learner = LearnerBuilder::create(
                $school, $grade, $stream, ['mode_of_study' => 'day_scholar']
            );

            $stop = (string) Str::uuid();
            $schedule = DB::table('transport_service_schedules')
                ->where('id', $f['schedule'])->first();
            DB::table('transport_route_stops')->insert([
                'id' => $stop, 'school_id' => $f['school'],
                'route_id' => $schedule->route_id,
                'direction' => 'to_school', 'stop_name' => 'Journey race stop',
                'stop_order' => 1,
                'created_by' => $f['actor'], 'updated_by' => $f['actor'],
            ]);

            $actor = User::withoutGlobalScopes()->findOrFail($f['actor']);
            $driver = User::withoutGlobalScopes()->findOrFail($f['driver_a']);

            $assignment = app(
                TransportAssignmentService::class
            )->create($actor, [
                'schedule_id' => $f['schedule'],
                'service_date' => '2026-11-09',
                'vehicle_id' => $f['vehicle_a'],
                'driver_id' => $f['driver_a'],
            ]);

            app(TransportLearnerReservationService::class)
                ->create($actor, [
                    'assignment_id' => $assignment['id'],
                    'learner_id' => $learner->id, 'stop_id' => $stop,
                ]);

            $guardian = (string) Str::uuid();
            $link = (string) Str::uuid();
            DB::table('parents')->insert([
                'id' => $guardian, 'school_id' => $f['school'],
                'first_name' => 'Race', 'last_name' => 'Guardian',
                'phone' => '+254700000001', 'active' => true, 'is_deleted' => false,
            ]);
            DB::table('learner_parents')->insert([
                'id' => $link, 'learner_id' => $learner->id,
                'parent_id' => $guardian, 'relationship' => 'Parent',
                'active' => true, 'is_deleted' => false,
                'can_pick_learner' => true, 'portal_enabled' => false,
            ]);

            $grant = app(
                TransportHandoverAuthorizationService::class
            )->create($actor, [
                'assignment_id' => $assignment['id'],
                'kind' => 'guardian_recipient',
                'learner_id' => $learner->id, 'stop_id' => $stop,
                'guardian_id' => $guardian, 'guardian_link_id' => $link,
                'valid_from' => '2026-11-09', 'valid_to' => '2026-11-09',
                'reason' => 'Concrete-run contention-test authority.',
            ]);

            $trip = app(TransportTripService::class)
                ->capture($driver, ['assignment_id' => $assignment['id']]);
            $roster = $trip['roster'][0]['id'];
            $journey = app(TransportJourneyEventService::class)
                ->show($driver, $roster);

            return $f + [
                'guardian' => $guardian,
                'grant' => $grant,
                'roster' => $roster,
                'journey_version' => $journey['version'],
            ];
        });
    }

    private function fixture(): array
    {
        $this->assertIsolatedDatabase();

        $f = DB::transaction(function (): array {
            $school = SchoolBuilder::create([
                'school_name' => 'Transport race '.Str::random(12),
                'short_name' => 'TRP',
                'registration_number' => 'REG-'.Str::random(12),
                'school_type' => 'Primary',
                'operating_mode' => 'day',
                'county' => 'Nairobi',
                'phone' => '+254700000001',
                'email' => 'transport-'.Str::random(12).'@example.test',
                'timezone' => 'Africa/Nairobi',
                'locale' => 'en',
                'lifecycle_state' => 'active',
            ]);
            $role = RoleBuilder::create();
            $actor = UserBuilder::create($school, $role);
            $a = UserBuilder::create($school, $role);
            $b = UserBuilder::create($school, $role);

            $year = AcademicYearBuilder::create($school);
            TermBuilder::create($school, $year);
            $grade = GradeBuilder::create($school);
            StreamBuilder::create($school, $grade);

            $permission = DB::table('permissions')
                ->where('permission_name', 'manage_transport')->value('id');
            if ($permission === null) {
                throw new \RuntimeException('Transport management permission missing.');
            }
            DB::table('role_permissions')->insert([
                'id' => (string) Str::uuid(),
                'role_id' => $role->id,
                'permission_id' => $permission,
                'created_at' => now(),
            ]);

            $route = (string) Str::uuid();
            DB::table('transport_routes')->insert([
                'id' => $route, 'school_id' => $school->id,
                'route_code' => 'R-'.Str::random(12),
                'route_name' => 'Race route',
                'created_by' => $actor->id, 'updated_by' => $actor->id,
            ]);

            $vehicles = [];
            foreach ([1, 2] as $unused) {
                $id = (string) Str::uuid();
                DB::table('transport_vehicles')->insert([
                    'id' => $id, 'school_id' => $school->id,
                    'registration_number' => 'BUS-'.Str::random(12),
                    'ownership_type' => 'school_owned', 'learner_capacity' => 20,
                    'created_by' => $actor->id, 'updated_by' => $actor->id,
                ]);
                $vehicles[] = $id;
            }

            $schedule = (string) Str::uuid();
            DB::table('transport_service_schedules')->insert([
                'id' => $schedule, 'school_id' => $school->id,
                'route_id' => $route, 'service_code' => 'S-'.Str::random(12),
                'direction' => 'to_school', 'timezone' => 'Africa/Nairobi',
                'weekday_mask' => 127,
                'effective_from' => '2026-11-01',
                'effective_to' => '2026-12-31',
                'departure_time' => '07:00', 'completion_time' => '08:00',
                'turnaround_minutes' => 15,
                'created_by' => $actor->id, 'updated_by' => $actor->id,
            ]);

            return [
                'school' => (string) $school->id,
                'role' => (string) $role->id,
                'actor' => (string) $actor->id,
                'driver_a' => (string) $a->id,
                'driver_b' => (string) $b->id,
                'vehicle_a' => $vehicles[0],
                'vehicle_b' => $vehicles[1],
                'schedule' => $schedule,
            ];
        });

        // Store immediately after commit so failed assertions still clean up.
        $this->raceFixture = $f;

        $this->assertTrue(
            app(SchoolSetupReadinessService::class)->isReady($f['school'])
        );
        $this->assertNotNull(User::withoutGlobalScopes()->find($f['actor']));

        return $f;
    }

    private function assertIsolatedDatabase(): void
    {
        $config = DB::connection()->getConfig();

        if (! app()->environment('testing')
            || app()->configurationIsCached()
            || ($config['driver'] ?? null) !== 'pgsql'
            || ($config['database'] ?? null) !== 'shuleos_assessment_fk_test'
            || ! empty($config['url'])
            || DB::transactionLevel() !== 0
            || DB::selectOne('SELECT current_database() AS name')->name
                !== 'shuleos_assessment_fk_test') {
            throw new \RuntimeException(
                'Exact isolated database and committed fixtures required.'
            );
        }
    }

    private function removeFixture(): void
    {
        $this->assertIsolatedDatabase();
        $school = $this->raceFixture['school'];
        $role = $this->raceFixture['role'];

        $tables = [
            'transport_daily_reviews',
            'transport_daily_submissions',
            'transport_reporting_obligations',
            'transport_reporting_policies',
            'transport_trip_completions',
            'transport_journey_outcomes',
            'transport_exception_follow_ups',
            'transport_journey_events',
            'transport_handover_revocations',
            'transport_handover_authorizations',
            'transport_trip_roster',
            'transport_trips',
            'transport_enrolment_transfers',
            'transport_enrolment_reservations',
            'transport_enrolment_episodes',
            'transport_learner_reservations',
            'transport_staff_reservations',
            'transport_dated_assignments',
            'transport_service_schedules',
            'transport_route_stops',
            'transport_routes',
            'transport_vehicles',
            'audit_logs',
            'learner_parents',
            'parents',
            'learners',
            'streams',
            'grades',
            'terms',
            'academic_years',
            'users',
            'schools',
            'role_permissions',
            'roles',
        ];

        DB::transaction(function () use ($tables, $school, $role): void {
            DB::statement("SET LOCAL lock_timeout = '5s'");
            $learners = DB::table('learners')->where('school_id', $school)->pluck('id');

            // Test-fixture cleanup only. Constraint triggers remain enabled.
            foreach ($tables as $table) {
                DB::statement('ALTER TABLE "'.$table.'" DISABLE TRIGGER USER');
            }

            foreach ($tables as $table) {
                $query = DB::table($table);

                if ($table === 'schools') {
                    $query->where('id', $school)->delete();
                } elseif ($table === 'roles') {
                    $query->where('id', $role)->delete();
                } elseif ($table === 'role_permissions') {
                    $query->where('role_id', $role)->delete();
                } elseif ($table === 'learner_parents') {
                    $query->whereIn('learner_id', $learners)->delete();
                } else {
                    $query->where('school_id', $school)->delete();
                }
            }

            foreach (array_reverse($tables) as $table) {
                DB::statement('ALTER TABLE "'.$table.'" ENABLE TRIGGER USER');
            }
        });

        $this->raceFixture = [];
    }
}
