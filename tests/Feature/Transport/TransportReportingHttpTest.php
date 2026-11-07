<?php

declare(strict_types=1);

namespace Tests\Feature\Transport;

use App\Services\Transport\TransportAuditService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\TransportHttpTestCase;

class TransportReportingHttpTest extends TransportHttpTestCase
{
    public function test_http_policy_creation_and_explicit_today_materialization_are_idempotent(): void
    {
        $f = $this->fixture();
        $this->httpAs($f['actor']);
        $schedule = DB::table('transport_dated_assignments')->where('id', $f['assignment']['id'])->value('schedule_id');
        $policy = $this->postJson('/api/transport/reporting/policies', [
            'schedule_id' => $schedule, 'effective_from' => '2026-11-09', 'effective_to' => '2026-11-30',
            'weekday_mask' => 127, 'deadline_time' => '17:00', 'deadline_day_offset' => 0, 'grace_minutes' => 30,
        ])->assertCreated()->json('data');
        $first = $this->postJson('/api/transport/reporting/policies/'.$policy['id'].'/materialize-today')->assertOk()->json('data');
        $before = $this->httpSnapshot($f);
        $this->postJson('/api/transport/reporting/policies/'.$policy['id'].'/materialize-today')->assertOk()->assertJsonPath('data.id', $first['id']);
        $this->getJson('/api/transport/reporting/obligations/'.$first['id'])->assertOk();
        $this->getJson('/api/transport/reporting/obligations/'.$first['id'].'/report')->assertOk()->assertJsonPath('data.state', 'missing');
        $this->postJson('/api/transport/reporting/policies/'.$policy['id'].'/materialize-today', ['service_date' => '2026-11-08'])->assertUnprocessable();
        $this->assertSame($before, $this->httpSnapshot($f));
    }

    public function test_http_submission_and_independent_review_preserve_frozen_evidence(): void
    {
        $f = $this->dailyFixture();
        $this->recordEvent($f, 'pickup', $f['guardianGrant']['id']);
        CarbonImmutable::setTestNow('2026-11-09T06:00:00Z');
        $this->httpAs($f['actor']);
        $current = $this->getJson('/api/transport/reporting/obligations/'.$f['obligation'].'/report')->assertOk()->json('data');
        $submitted = $this->postJson('/api/transport/reporting/obligations/'.$f['obligation'].'/submissions', [
            'expected_version' => $current['version'], 'summary' => 'Daily service account based on actual handovers.',
            'exception_note' => 'Learner remains onboard pending verified school handover.',
        ])->assertCreated()->assertJsonPath('data.state', 'submitted')->json('data');
        $submission = $submitted['submissions'][0];
        $this->assertSame(1, $submission['snapshot']['totals']['on_board']);
        $this->grant($f['actor'], 'review_transport_daily_reports');
        $review = [
            'expected_version' => $submission['review_version'], 'decision' => 'accepted',
            'note' => 'Independent review acknowledges the submitted custody account.',
        ];
        $this->postJson('/api/transport/reporting/submissions/'.$submission['id'].'/review', $review)->assertForbidden();
        $this->httpAs($f['receiver']);
        $this->postJson('/api/transport/rosters/'.$f['roster'].'/events', $this->input($f, 'school_arrival', $f['schoolGrant']['id']))->assertCreated();
        $this->httpAs($this->reviewer($f));
        $result = $this->postJson('/api/transport/reporting/submissions/'.$submission['id'].'/review', $review)->assertCreated()->assertJsonPath('data.state', 'accepted')->json('data');
        $this->assertSame($submission['snapshot'], $result['submissions'][0]['snapshot']);
        $this->assertSame(0, $result['current_snapshot']['totals']['on_board']);
        $this->postJson('/api/transport/reporting/submissions/'.$submission['id'].'/review', $review)->assertConflict();
    }

    public function test_changes_requested_allow_a_new_immutable_revision(): void
    {
        $f = $this->dailyFixture();
        CarbonImmutable::setTestNow('2026-11-09T06:00:00Z');
        $first = $this->submitDaily($f)['submissions'][0];
        $this->httpAs($this->reviewer($f));
        $this->postJson('/api/transport/reporting/submissions/'.$first['id'].'/review', [
            'expected_version' => $first['review_version'], 'decision' => 'changes_requested',
            'note' => 'Clarify the incomplete service and outstanding learner outcomes.',
        ])->assertCreated();
        $this->httpAs($f['actor']);
        $current = $this->getJson('/api/transport/reporting/obligations/'.$f['obligation'].'/report')->assertOk()->json('data');
        $this->postJson('/api/transport/reporting/obligations/'.$f['obligation'].'/submissions', [
            'expected_version' => $current['version'], 'summary' => 'Revised end of service account with additional explanation.',
            'exception_note' => 'Outstanding outcomes remain under explicitly recorded follow-up.',
        ])->assertCreated()->assertJsonPath('data.submissions.1.revision', 2)->assertJsonPath('data.submissions.0.id', $first['id']);
    }

    public function test_missing_overdue_and_unassigned_visibility_has_no_read_side_effects(): void
    {
        $f = $this->fixture();
        $this->policy($f);
        CarbonImmutable::setTestNow('2026-11-09T14:30:01Z');
        $this->httpAs($f['actor']);
        $before = $this->httpSnapshot($f);
        $this->getJson('/api/transport/reporting/obligations?overdue=1&responsibility=unassigned')
            ->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.report_state', 'missing')
            ->assertJsonPath('data.0.materialization_state', 'pending')->assertJsonPath('data.0.overdue', true);
        $this->getJson('/api/transport/reporting/obligations?limit=101')->assertUnprocessable();
        $this->getJson('/api/transport/reporting/obligations?school_id='.$f['school']->id)->assertUnprocessable()->assertJsonValidationErrors('school_id');
        $this->assertSame($before, $this->httpSnapshot($f));
    }

    public function test_reporting_permissions_and_concrete_responsibility_are_not_implied_by_management(): void
    {
        $f = $this->journey();
        $f['obligation'] = $this->reporting()->materialize($f['actor'], $this->policy($f)['id'])['id'];
        $this->httpAs($f['actor']);
        $this->postJson('/api/transport/reporting/obligations/'.$f['obligation'].'/submissions', [])->assertForbidden();
        $this->postJson('/api/transport/reporting/submissions/'.Str::uuid().'/review', [])->assertForbidden();
        $this->grant($f['actor'], 'submit_transport_daily_reports');
        CarbonImmutable::setTestNow('2026-11-09T06:00:00Z');
        $this->httpAs($f['receiver']);
        $current = $this->getJson('/api/transport/reporting/obligations/'.$f['obligation'].'/report')->assertOk()->json('data');
        $this->postJson('/api/transport/reporting/obligations/'.$f['obligation'].'/submissions', [
            'expected_version' => $current['version'], 'summary' => 'Unassigned staff cannot submit this account.',
            'exception_note' => 'An explanation does not establish crew responsibility.',
        ])->assertForbidden();
    }

    public function test_foreign_obligations_submissions_and_reporting_filters_fail_both_ways(): void
    {
        $a = $this->dailyFixture();
        $b = $this->dailyFixture();
        CarbonImmutable::setTestNow('2026-11-09T06:00:00Z');
        $a['submission'] = $this->submitDaily($a)['submissions'][0];
        $b['submission'] = $this->submitDaily($b)['submissions'][0];
        foreach ([[$a, $b], [$b, $a]] as [$own,$foreign]) {
            $this->grant($own['actor'], 'review_transport_daily_reports');
            $this->httpAs($own['actor']);
            $this->getJson('/api/transport/reporting/obligations/'.$foreign['obligation'])->assertNotFound();
            $this->getJson('/api/transport/reporting/obligations/'.$foreign['obligation'].'/report')->assertNotFound();
            $this->postJson('/api/transport/reporting/obligations/'.$foreign['obligation'].'/submissions', [
                'expected_version' => str_repeat('a', 64), 'summary' => 'Foreign submission rejected.',
            ])->assertNotFound();
            $this->postJson('/api/transport/reporting/submissions/'.$foreign['submission']['id'].'/review', [
                'expected_version' => $foreign['submission']['review_version'], 'decision' => 'accepted', 'note' => 'Foreign review rejected.',
            ])->assertNotFound();
            $schedule = DB::table('transport_dated_assignments')->where('id', $foreign['assignment']['id'])->value('schedule_id');
            $this->getJson('/api/transport/reporting/obligations?schedule_id='.$schedule)->assertNotFound();
        }
    }

    public function test_report_validation_rejects_client_totals_actors_and_stale_versions(): void
    {
        $f = $this->dailyFixture();
        $this->httpAs($f['actor']);
        $current = $this->getJson('/api/transport/reporting/obligations/'.$f['obligation'].'/report')->assertOk()->json('data');
        $this->recordEvent($f, 'pickup', $f['guardianGrant']['id']);
        CarbonImmutable::setTestNow('2026-11-09T06:00:00Z');
        $this->httpAs($f['actor']);
        $input = ['expected_version' => $current['version'], 'summary' => 'End of service account.', 'exception_note' => 'Crew retains custody pending handover.'];
        $before = $this->httpSnapshot($f);
        $this->postJson('/api/transport/reporting/obligations/'.$f['obligation'].'/submissions', $input)->assertConflict();
        foreach (['snapshot', 'totals', 'submitted_by', 'submitted_at'] as $field) {
            $this->postJson('/api/transport/reporting/obligations/'.$f['obligation'].'/submissions', [...$input, $field => 'fabricated'])
                ->assertUnprocessable()->assertJsonValidationErrors($field);
        }
        $this->assertSame($before, $this->httpSnapshot($f));
    }

    public function test_http_audit_failure_rolls_back_resource_and_report_mutations(): void
    {
        $f = $this->dailyFixture();
        CarbonImmutable::setTestNow('2026-11-09T06:00:00Z');
        $this->httpAs($f['actor']);
        $current = $this->getJson('/api/transport/reporting/obligations/'.$f['obligation'].'/report')->assertOk()->json('data');
        $before = $this->httpSnapshot($f);
        $this->mock(TransportAuditService::class)->shouldReceive('record')->andThrow(new \RuntimeException('HTTP strict audit failure.'));
        $this->postJson('/api/transport/routes', ['route_code' => 'ROLLBACK', 'route_name' => 'Must roll back'])->assertStatus(500);
        $this->postJson('/api/transport/reporting/obligations/'.$f['obligation'].'/submissions', [
            'expected_version' => $current['version'], 'summary' => 'Failed audit must roll back the submission.',
            'exception_note' => 'Outstanding issues remain explicitly recorded.',
        ])->assertStatus(500);
        $this->assertSame($before, $this->httpSnapshot($f));
    }
}
