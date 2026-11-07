<?php

declare(strict_types=1);

namespace Tests\Feature\Transport;

use App\Models\User;
use App\Services\SchoolSetupReadinessService;
use App\Services\Transport\TransportAssignmentService;
use App\Services\Transport\TransportAuditService;
use App\Services\Transport\TransportDailyReportService;
use App\Services\Transport\TransportHandoverAuthorizationService;
use App\Services\Transport\TransportJourneyEventService;
use App\Services\Transport\TransportLearnerReservationService;
use App\Services\Transport\TransportReportingObligationService;
use App\Services\Transport\TransportTripService;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
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
use Tests\TestCase;

class TransportDailyReportServiceTest extends TestCase
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

    public function test_submission_freezes_totals_and_from_to_evidence(): void
    {
        $f = $this->dailyFixture();
        $this->recordEvent($f, 'pickup', $f['guardianGrant']['id']);
        CarbonImmutable::setTestNow('2026-11-09T06:00:00Z');
        $submitted = $this->submitDaily($f);
        $saved = $submitted['submissions'][0];
        $this->assertSame(1, $saved['snapshot']['totals']['on_board']);
        $this->assertSame(1, $saved['snapshot']['totals']['transported_legs']);
        $event = $saved['snapshot']['runs'][0]['events'][0];
        $this->assertSame($f['guardian'], $event['from_guardian_id']);
        $this->assertSame($f['actor']->id, $event['to_user_id']);
        $this->assertNotEmpty($event['authorization_snapshot']);
        $this->assertSame('submitted', $submitted['state']);
        $this->recordEvent($f, 'school_arrival', $f['schoolGrant']['id'], $f['receiver']);
        $current = $this->daily()->show($f['actor'], $f['obligation']);
        $this->assertSame($saved, $current['submissions'][0]);
        $this->assertSame(0, $current['current_snapshot']['totals']['on_board']);
        $this->assertSame(1, $current['current_snapshot']['totals']['handed_over']);
    }

    public function test_open_custody_requires_account_and_submission_does_not_resolve_it(): void
    {
        $f = $this->dailyFixture();
        $this->recordEvent($f, 'pickup', $f['guardianGrant']['id']);
        $this->events()->record($f['actor'], $f['roster'], [
            ...$this->input($f, 'school_arrival', $f['schoolGrant']['id']),
            'outcome' => 'failed', 'receipt_method' => 'none',
            'failure_reason' => 'person_unavailable',
            'follow_up' => 'Crew retains custody pending verified school handover.',
        ]);
        CarbonImmutable::setTestNow('2026-11-09T06:00:00Z');
        $before = $this->daily()->show($f['actor'], $f['obligation']);
        $this->denied(fn () => $this->daily()->submit($f['actor'], $f['obligation'], [
            'expected_version' => $before['version'], 'summary' => 'End of service account.',
        ]), 422);
        $result = $this->submitDaily($f);
        $this->assertSame(1, $result['submissions'][0]['snapshot']['totals']['unresolved_exceptions']);
        $this->assertSame(1, $result['submissions'][0]['snapshot']['totals']['on_board']);
        $this->assertSame(0, DB::table('transport_exception_follow_ups')->where('school_id', $f['school']->id)->where('action', 'resolve')->count());
        $this->assertSame('on_board', $this->events()->show($f['actor'], $f['roster'])['state']);
    }

    public function test_service_window_and_stale_evidence_block_submission(): void
    {
        $f = $this->dailyFixture();
        $this->denied(fn () => $this->submitDaily($f), 422);
        $before = $this->daily()->show($f['actor'], $f['obligation']);
        $this->recordEvent($f, 'pickup', $f['guardianGrant']['id']);
        CarbonImmutable::setTestNow('2026-11-09T06:00:00Z');
        $this->denied(fn () => $this->daily()->submit($f['actor'], $f['obligation'], [
            'expected_version' => $before['version'], 'summary' => 'End of service account.',
            'exception_note' => 'Outstanding issues remain under active custody follow-up.',
        ]), 409);
        $this->assertSame(0, DB::table('transport_daily_submissions')->where('school_id', $f['school']->id)->count());
    }

    public function test_independent_review_targets_frozen_revision_after_live_changes(): void
    {
        $f = $this->dailyFixture();
        $this->recordEvent($f, 'pickup', $f['guardianGrant']['id']);
        CarbonImmutable::setTestNow('2026-11-09T06:00:00Z');
        $submitted = $this->submitDaily($f)['submissions'][0];
        $this->recordEvent($f, 'school_arrival', $f['schoolGrant']['id'], $f['receiver']);
        $reviewer = $this->reviewer($f);
        $result = $this->daily()->review($reviewer, $submitted['id'], [
            'expected_version' => $submitted['review_version'], 'decision' => 'accepted',
            'note' => 'Reviewed the submitted account and outstanding follow-up evidence.',
        ]);
        $this->assertSame('accepted', $result['state']);
        $this->assertSame($submitted['snapshot'], $result['submissions'][0]['snapshot']);
        $this->assertSame(1, $result['submissions'][0]['snapshot']['totals']['on_board']);
        $this->assertSame(0, $result['current_snapshot']['totals']['on_board']);
        $this->denied(fn () => $this->daily()->review($reviewer, $submitted['id'], [
            'expected_version' => $submitted['review_version'], 'decision' => 'accepted',
            'note' => 'A duplicate review must be rejected.',
        ]), 409);
        $this->denied(fn () => $this->submitDaily($f), 409);
    }

    public function test_submitter_and_school_handover_participant_cannot_review(): void
    {
        $f = $this->dailyFixture();
        $this->recordEvent($f, 'pickup', $f['guardianGrant']['id']);
        $this->recordEvent($f, 'school_arrival', $f['schoolGrant']['id'], $f['receiver']);
        CarbonImmutable::setTestNow('2026-11-09T06:00:00Z');
        $submission = $this->submitDaily($f)['submissions'][0];
        $this->grant($f['actor'], 'review_transport_daily_reports');
        foreach ([$f['actor'], $f['receiver']] as $participant) {
            $this->denied(fn () => $this->daily()->review($participant, $submission['id'], [
                'expected_version' => $submission['review_version'], 'decision' => 'accepted',
                'note' => 'Operational participants cannot independently review.',
            ]), 403);
        }
    }

    public function test_requested_changes_create_new_revision_without_rewriting_history(): void
    {
        $f = $this->dailyFixture();
        CarbonImmutable::setTestNow('2026-11-09T06:00:00Z');
        $first = $this->submitDaily($f)['submissions'][0];
        $this->denied(fn () => $this->submitDaily($f), 409);
        $reviewer = $this->reviewer($f);
        $reviewed = $this->daily()->review($reviewer, $first['id'], [
            'expected_version' => $first['review_version'], 'decision' => 'changes_requested',
            'note' => 'Provide a clearer account of the outstanding service outcomes.',
        ]);
        $original = $reviewed['submissions'][0];
        $second = $this->submitDaily($f);
        $this->assertCount(2, $second['submissions']);
        $this->assertSame($original, $second['submissions'][0]);
        $this->assertSame(2, $second['submissions'][1]['revision']);
        $this->assertSame($first['id'], $second['submissions'][1]['previous_id']);
        $this->assertSame('submitted', $second['state']);
        $this->denied(fn () => $this->daily()->review($reviewer, $first['id'], [
            'expected_version' => $first['review_version'], 'decision' => 'accepted',
            'note' => 'An obsolete revision cannot be accepted.',
        ]), 409);
    }

    public function test_permissions_and_concrete_responsibility_are_separate(): void
    {
        $f = $this->journey();
        $f['obligation'] = $this->reporting()->materialize($f['actor'], $this->policy($f)['id'])['id'];
        CarbonImmutable::setTestNow('2026-11-09T06:00:00Z');
        $this->denied(fn () => $this->submitDaily($f), 403);
        $this->grant($f['actor'], 'submit_transport_daily_reports');
        $unassigned = User::withoutGlobalScopes()->findOrFail($f['unassigned']->id);
        $before = $this->daily()->show($f['actor'], $f['obligation']);
        $this->denied(fn () => $this->daily()->submit($unassigned, $f['obligation'], [
            'expected_version' => $before['version'], 'summary' => 'Unassigned account must fail.',
            'exception_note' => 'Outstanding issues remain explicitly recorded.',
        ]), 403);
        $submission = $this->submitDaily($f)['submissions'][0];
        $this->denied(fn () => $this->daily()->review($unassigned, $submission['id'], [
            'expected_version' => $submission['review_version'], 'decision' => 'accepted',
            'note' => 'Submission permission cannot grant review permission.',
        ]), 403);
    }

    public function test_foreign_reports_submissions_and_reviews_fail_both_ways(): void
    {
        $a = $this->dailyFixture();
        $b = $this->dailyFixture();
        CarbonImmutable::setTestNow('2026-11-09T06:00:00Z');
        foreach ([[$a, $b], [$b, $a]] as [$owner, $foreign]) {
            $submission = $this->submitDaily($owner)['submissions'][0];
            $this->denied(fn () => $this->daily()->show($foreign['actor'], $owner['obligation']), 404);
            $this->denied(fn () => $this->daily()->submit($foreign['actor'], $owner['obligation'], [
                'expected_version' => str_repeat('a', 64), 'summary' => 'Foreign submission rejected.',
            ]), 404);
            $this->denied(fn () => $this->daily()->review($this->reviewer($foreign), $submission['id'], [
                'expected_version' => $submission['review_version'], 'decision' => 'accepted',
                'note' => 'Foreign review must remain tenant isolated.',
            ]), 404);
        }
    }

    public function test_submissions_and_reviews_are_immutable_in_the_database(): void
    {
        $f = $this->dailyFixture();
        CarbonImmutable::setTestNow('2026-11-09T06:00:00Z');
        $submission = $this->submitDaily($f)['submissions'][0];
        $reviewed = $this->daily()->review($this->reviewer($f), $submission['id'], [
            'expected_version' => $submission['review_version'], 'decision' => 'accepted',
            'note' => 'Independent review of the original evidence.',
        ]);
        foreach ([
            ['transport_daily_submissions', $submission['id'], 'summary'],
            ['transport_daily_reviews', $reviewed['submissions'][0]['review']['id'], 'note'],
        ] as [$table, $id, $field]) {
            foreach (['update', 'delete'] as $operation) {
                try {
                    DB::transaction(function () use ($table, $id, $field, $operation): void {
                        $query = DB::table($table)->where('id', $id);
                        if ($operation === 'update') {
                            $query->update([$field => 'Attempted evidence rewrite.']);
                        } else {
                            $query->delete();
                        }
                    });
                    $this->fail('Immutable evidence was changed.');
                } catch (QueryException $exception) {
                    $this->assertSame('23514', $exception->errorInfo[0]);
                }
            }
        }
    }

    public function test_audit_failure_rolls_back_submission_and_review(): void
    {
        $f = $this->dailyFixture();
        CarbonImmutable::setTestNow('2026-11-09T06:00:00Z');
        $before = $this->daily()->show($f['actor'], $f['obligation']);
        $this->mock(TransportAuditService::class)
            ->shouldReceive('record')->andThrow(new \RuntimeException('Strict daily audit failure.'));
        app()->forgetInstance(TransportDailyReportService::class);
        try {
            $this->submitDaily($f);
            $this->fail('Submission audit failure was ignored.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Strict daily audit failure.', $exception->getMessage());
        }
        $this->assertSame($before, $this->daily()->show($f['actor'], $f['obligation']));
        app()->forgetInstance(TransportAuditService::class);
        app()->forgetInstance(TransportDailyReportService::class);
        $submission = $this->submitDaily($f)['submissions'][0];
        $reviewer = $this->reviewer($f);
        $this->mock(TransportAuditService::class)
            ->shouldReceive('record')->andThrow(new \RuntimeException('Strict review audit failure.'));
        app()->forgetInstance(TransportDailyReportService::class);
        try {
            $this->daily()->review($reviewer, $submission['id'], [
                'expected_version' => $submission['review_version'], 'decision' => 'accepted',
                'note' => 'Review must roll back with failed strict audit.',
            ]);
            $this->fail('Review audit failure was ignored.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Strict review audit failure.', $exception->getMessage());
        }
        $this->assertSame(0, DB::table('transport_daily_reviews')->where('submission_id', $submission['id'])->count());
    }

    public function test_deadline_equality_is_on_time_and_later_revision_preserves_first_timing(): void
    {
        $f = $this->dailyFixture();
        CarbonImmutable::setTestNow('2026-11-09T14:00:00Z');
        $first = $this->submitDaily($f)['submissions'][0];
        $this->assertFalse($first['late']);
        $this->assertFalse($first['overdue']);
        $this->daily()->review($this->reviewer($f), $first['id'], [
            'expected_version' => $first['review_version'], 'decision' => 'changes_requested',
            'note' => 'Request an additional explicit service account.',
        ]);
        CarbonImmutable::setTestNow('2026-11-09T14:30:01Z');
        $result = $this->submitDaily($f);
        $this->assertFalse($result['submissions'][0]['late']);
        $this->assertTrue($result['submissions'][1]['late']);
        $this->assertTrue($result['submissions'][1]['overdue']);
    }

    public function test_unknown_fields_cannot_supply_totals_or_fabricated_actor(): void
    {
        $f = $this->dailyFixture();
        CarbonImmutable::setTestNow('2026-11-09T06:00:00Z');
        $before = $this->daily()->show($f['actor'], $f['obligation']);
        foreach (['snapshot', 'submitted_by', 'submitted_at', 'totals'] as $field) {
            $this->denied(fn () => $this->daily()->submit($f['actor'], $f['obligation'], [
                'expected_version' => $before['version'], 'summary' => 'Service account with prohibited field.',
                $field => 'fabricated',
            ]), 422);
        }
        $this->assertSame('missing', $before['state']);
    }

    public function test_database_rejects_forged_snapshot_and_self_review(): void
    {
        $f = $this->dailyFixture();
        CarbonImmutable::setTestNow('2026-11-09T06:00:00Z');
        $before = $this->daily()->show($f['actor'], $f['obligation']);
        $forged = $before['current_snapshot'];
        $forged['totals']['on_board'] = 999;
        try {
            DB::transaction(function () use ($f, $forged): void {
                DB::table('transport_daily_submissions')->insert([
                    'id' => (string) Str::uuid(), 'school_id' => $f['school']->id,
                    'obligation_id' => $f['obligation'], 'revision' => 1,
                    'summary' => 'Forged direct SQL report must be rejected.',
                    'exception_note' => 'Explicit account cannot authorize invented totals.',
                    'snapshot' => json_encode($forged, JSON_THROW_ON_ERROR),
                    'submitted_by' => $f['actor']->id,
                    'submitted_at' => CarbonImmutable::now('UTC')->toIso8601String(),
                ]);
            });
            $this->fail('Forged SQL snapshot was accepted.');
        } catch (QueryException $exception) {
            $this->assertSame('23P01', $exception->errorInfo[0]);
        }
        $submission = $this->submitDaily($f)['submissions'][0];
        try {
            DB::transaction(function () use ($f, $submission): void {
                DB::table('transport_daily_reviews')->insert([
                    'id' => (string) Str::uuid(), 'school_id' => $f['school']->id,
                    'submission_id' => $submission['id'], 'decision' => 'accepted',
                    'note' => 'Direct SQL self review must be rejected.',
                    'reviewed_by' => $f['actor']->id,
                    'reviewed_at' => CarbonImmutable::now('UTC')->toIso8601String(),
                ]);
            });
            $this->fail('Direct SQL self review was accepted.');
        } catch (QueryException $exception) {
            $this->assertSame('23514', $exception->errorInfo[0]);
        }
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
