<?php

declare(strict_types=1);

namespace App\Services\StudentElectionSubscription;

use App\Models\StudentElection;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Transaction-scoped commercial gate; domain authentication and permissions remain mandatory. */
final class StudentElectionCommercialAccess
{
    private function context(string $school): array
    {
        $c = app(StudentElectionCommercialEvaluator::class)->context($school);
        $s = $c['s'];
        abort_unless($s !== null && $s->active && ! $s->is_deleted && ! in_array($s->lifecycle_state, ['suspended', 'locked', 'archived'], true), 403);

        return $c;
    }

    public function normal(string $school): array
    {
        $c = $this->context($school);
        abort_unless($c['main'] && ($c['included'] || $c['period'] !== null), 403, 'Current Elections entitlement and an eligible main subscription are required for new work.');

        return ['period_id' => ($c['included'] ? null : $c['period']->id), 'main_subscription_id' => $c['base']->id, 'checked_at' => $c['at']->toIso8601String()];
    }

    private function window(StudentElection $e, ?object $round): array
    {
        if ($round === null) {
            return [$e->id, $e->voting_opens_at->toIso8601String(), $e->voting_closes_at->toIso8601String()];
        }
        abort_unless($round->election_id === $e->id && $round->school_id === $e->school_id, 404);
        $d = json_decode($round->definition, true, 512, JSON_THROW_ON_ERROR);

        return [$round->id, $d['opens_at'], $d['closes_at']];
    }

    private function receipt(StudentElection $e, ?object $round = null): ?object
    {
        [$scope, $opens, $closes] = $this->window($e, $round);
        $r = DB::table('student_election_commercial_openings')->where('school_id', $e->school_id)->where('election_id', $e->id)->where('scope_id', $scope)->first();
        if ($r === null || ! CarbonImmutable::parse($r->opens_at)->equalTo(CarbonImmutable::parse($opens))
            || ! CarbonImmutable::parse($r->closes_at)->equalTo(CarbonImmutable::parse($closes))) {
            return null;
        }
        if ($r->period_id === null) {
            return $r;
        }
        $period = DB::table('student_election_addon_periods')->where('school_id', $e->school_id)->where('id', $r->period_id)->first();
        if ($period === null || CarbonImmutable::parse($r->checked_at)->lt(CarbonImmutable::parse($period->starts_at))
            || CarbonImmutable::parse($r->checked_at)->gte(CarbonImmutable::parse($period->ends_at))) {
            return null;
        }

        return $r;
    }

    public function opened(StudentElection $e, string $actor, ?object $round = null): void
    {
        abort_unless(DB::table('users')->where('id', $actor)->where('school_id', $e->school_id)->sharedLock()->first() !== null, 403);
        $basis = $this->normal($e->school_id);
        [$scope, $opens, $closes] = $this->window($e, $round);
        $existing = $this->receipt($e, $round);
        if ($existing !== null) {
            return;
        }
        $id = (string) Str::uuid();
        DB::table('student_election_commercial_openings')->insert($basis + ['id' => $id, 'school_id' => $e->school_id, 'election_id' => $e->id,
            'scope_id' => $scope, 'round_id' => $round?->id, 'opens_at' => CarbonImmutable::parse($opens)->utc()->toIso8601String(),
            'closes_at' => CarbonImmutable::parse($closes)->utc()->toIso8601String(), 'created_by' => $actor]);
        if (! DB::table('student_election_audit_logs')->insert(['id' => (string) Str::uuid(), 'school_id' => $e->school_id, 'election_id' => $e->id,
            'actor_user_id' => $actor, 'action' => 'commercial_opening_recorded', 'entity_type' => 'commercial_opening', 'entity_id' => $id,
            'metadata' => json_encode(['period_id' => $basis['period_id']], JSON_THROW_ON_ERROR), 'created_at' => now()])) {
            throw new \RuntimeException('Opening audit failed.');
        }
    }

    public function live(StudentElection $e, ?object $round = null): void
    {
        $c = $this->context($e->school_id);
        if ($c['main'] && ($c['included'] || $c['period'] !== null)) {
            return;
        }
        $r = $this->receipt($e, $round);
        abort_unless($c['main'] && $r !== null && $c['at']->gte(CarbonImmutable::parse($r->opens_at)) && $c['at']->lt(CarbonImmutable::parse($r->closes_at)), 403,
            'Expired access can only complete a previously entitled opening within its recorded window.');
    }

    public function available(StudentElection $e): void
    {
        if (in_array($e->status, ['draft', 'nominations_open', 'nominations_closed', 'campaigning'], true)) {
            $this->normal($e->school_id);

            return;
        }
        if ($e->status === 'voting_open') {
            $this->live($e);

            return;
        }
        $c = $this->context($e->school_id);
        // Published evidence remains readable and its existing declaration can be verified idempotently.
        $historical = $e->status === 'published' && DB::table('student_election_declarations')->where('school_id', $e->school_id)->where('election_id', $e->id)->exists();
        abort_unless(($c['main'] && ($c['included'] || $c['period'] !== null)) || $this->receipt($e) !== null || $historical, 403,
            'An opening receipt or current entitlement is required for controlled completion.');
    }
}
