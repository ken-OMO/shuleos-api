<?php

declare(strict_types=1);

namespace App\Services\TransportSubscription;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use LogicException;

/** Commercial access only; TransportAccessService retains identity and domain permissions. */
class TransportEntitlementService
{
    public function normal(User $actor): ?array
    {
        $this->transaction();
        $school = $actor->school;
        if ($school->lifecycle_state !== 'active') {
            return null;
        }
        // The latest main subscription is authoritative; package names never grant access.
        $base = DB::table('school_subscriptions')->where('school_id', $actor->school_id)
            ->orderByDesc('created_at')->orderByDesc('id')->sharedLock()->first();
        // Evaluate commercial time only after all commercial-row lock waits.
        $at = CarbonImmutable::now('UTC');
        $period = DB::table('transport_addon_periods')->where('school_id', $actor->school_id)
            ->where('starts_at', '<=', $at->toIso8601String())
            ->where('ends_at', '>', $at->toIso8601String())->first();
        if ($period === null) {
            return null;
        }
        $date = $at->setTimezone($school->timezone ?: config('app.timezone', 'UTC'))->toDateString();
        if ($base === null || $base->package_id === null || $base->start_date === null
            || $base->start_date > $date || $base->expiry_date === null) {
            return null;
        }
        $end = $base->status === 'GRACE_PERIOD' ? $base->grace_end_date : $base->expiry_date;
        if (! in_array($base->status, ['ACTIVE', 'GRACE_PERIOD'], true)
            || $end === null || $end < $date) {
            return null;
        }

        return ['mode' => 'normal', 'period_id' => (string) $period->id,
            'main_subscription_id' => (string) $base->id,
            'checked_at' => $at->toIso8601String()];
    }

    public function requireNormal(User $actor): array
    {
        $basis = $this->normal($actor);
        abort_unless($basis !== null, 403, 'An effective Transport add-on and eligible main subscription are required.');

        return $basis;
    }

    public function journey(User $actor, string $rosterId, string $boundary): array
    {
        $this->transaction();
        abort_unless(in_array($boundary, ['school_arrival', 'destination_dropoff'], true), 403,
            'Expired or restricted access cannot start a new learner journey.');
        [$roster, $trip] = $this->roster($actor, $rosterId);
        abort_unless(($trip->direction === 'to_school' && $boundary === 'school_arrival')
            || ($trip->direction === 'from_school' && $boundary === 'destination_dropoff'), 403);
        $last = DB::table('transport_journey_events')->where('school_id', $actor->school_id)
            ->where('roster_id', $rosterId)->orderByDesc('sequence')->first();
        abort_unless($last !== null && $last->state_after === 'on_board', 403,
            'Continuation requires a learner already in Transport custody.');

        return $this->boardingBasis($actor, $roster, $trip, 'custody_handover');
    }

    public function attendance(User $actor, string $rosterId): array
    {
        [, $trip] = $this->roster($actor, $rosterId);

        return $this->capturedBasis($actor, $trip, 'captured_attendance', $rosterId);
    }

    public function completion(User $actor, string $tripId): array
    {
        $trip = DB::table('transport_trips')->where('school_id', $actor->school_id)
            ->where('id', $tripId)->first();
        abort_unless($trip !== null, 404);

        return $this->capturedBasis($actor, $trip, 'captured_trip_completion', $tripId);
    }

    public function exception(User $actor, string $failureId): array
    {
        $failure = DB::table('transport_journey_events')->where('school_id', $actor->school_id)
            ->where('id', $failureId)->where('outcome', 'failed')->first();
        abort_unless($failure !== null, 404);
        abort_unless(in_array($failure->boundary, ['school_arrival', 'destination_dropoff'], true), 403);
        [$roster, $trip] = $this->roster($actor, (string) $failure->roster_id);

        // May resolve after delivery; the original failed handover is retained.
        return $this->boardingBasis($actor, $roster, $trip, 'custody_follow_up');
    }

    public function authorization(User $actor, array $data): array
    {
        $trip = DB::table('transport_trips')->where('school_id', $actor->school_id)
            ->where('assignment_id', $data['assignment_id'])->first();
        abort_unless($trip !== null, 403, 'Replacement authorization requires a captured trip.');
        abort_unless($data['valid_from'] === $trip->service_date
            && $data['valid_to'] === $trip->service_date, 403,
            'Continuation authorizations are limited to the original dated service.');
        $guardian = $data['kind'] === 'guardian_recipient';
        abort_unless(($guardian && $trip->direction === 'from_school')
            || ($data['kind'] === 'school_receiver' && $trip->direction === 'to_school'), 403);
        $query = DB::table('transport_trip_roster')->where('school_id', $actor->school_id)
            ->where('trip_id', $trip->id);
        if ($guardian) {
            $query->where('learner_id', $data['learner_id'])->where('stop_id', $data['stop_id']);
        }
        foreach ($query->orderBy('id')->get() as $roster) {
            $last = DB::table('transport_journey_events')->where('school_id', $actor->school_id)
                ->where('roster_id', $roster->id)->orderByDesc('sequence')->first();
            if ($last !== null && $last->state_after === 'on_board') {
                return $this->boardingBasis($actor, $roster, $trip, 'custody_recipient_authorization');
            }
        }
        abort(403, 'Replacement authorization requires ongoing learner custody.');
    }

    public function revocation(User $actor, string $id): array
    {
        $row = DB::table('transport_handover_authorizations')->where('school_id', $actor->school_id)
            ->where('id', $id)->first();
        abort_unless($row !== null, 404);

        return $this->authorization($actor, (array) $row);
    }

    public function report(User $actor, string $id, bool $submission = false): array
    {
        if ($submission) {
            $row = DB::table('transport_daily_submissions')->where('school_id', $actor->school_id)
                ->where('id', $id)->first();
            abort_unless($row !== null, 404);
            $id = (string) $row->obligation_id;
        }
        $row = DB::table('transport_reporting_obligations')->where('school_id', $actor->school_id)
            ->where('id', $id)->first();
        abort_unless($row !== null, 404);
        $schedule = json_decode($row->schedule_snapshot, true, 512, JSON_THROW_ON_ERROR);
        $departure = CarbonImmutable::parse($row->service_date.' '.$schedule['departure_time'], $schedule['timezone'])->utc();
        $period = $this->periodCovering($actor, [$row->materialized_at, $departure->toIso8601String()]);

        return $this->basis($period, 'preexisting_service_report', $id);
    }

    private function roster(User $actor, string $id): array
    {
        $this->transaction();
        $roster = DB::table('transport_trip_roster')->where('school_id', $actor->school_id)->where('id', $id)->first();
        abort_unless($roster !== null, 404);
        $trip = DB::table('transport_trips')->where('school_id', $actor->school_id)->where('id', $roster->trip_id)->first();
        abort_unless($trip !== null, 404);

        return [$roster, $trip];
    }

    private function capturedBasis(User $actor, object $trip, string $scope, string $id): array
    {
        return $this->basis($this->periodCovering($actor, [$trip->captured_at]), $scope, $id);
    }

    private function boardingBasis(User $actor, object $roster, object $trip, string $scope): array
    {
        $boarding = DB::table('transport_journey_events')->where('school_id', $actor->school_id)
            ->where('roster_id', $roster->id)->where('outcome', 'recorded')
            ->whereIn('boundary', ['pickup', 'school_departure'])->where('state_after', 'on_board')
            ->orderBy('sequence')->first();
        abort_unless($boarding !== null, 403, 'Pre-expiry boarding evidence is required.');
        $period = $this->periodCovering($actor, [$trip->captured_at, $boarding->occurred_at, $boarding->recorded_at]);

        return $this->basis($period, $scope, (string) $roster->id);
    }

    private function periodCovering(User $actor, array $instants): object
    {
        $this->transaction();
        $period = null;
        foreach ($instants as $instant) {
            // Capture and boarding may span adjacent renewal periods.
            $period = DB::table('transport_addon_periods')->where('school_id', $actor->school_id)
                ->where('starts_at', '<=', $instant)->where('ends_at', '>', $instant)->first();
            abort_unless($period !== null, 403, 'Existing evidence must belong to a recorded Transport entitlement period.');
        }
        if ($period === null) {
            throw new LogicException('Continuation evidence instants are required.');
        }

        return $period;
    }

    private function basis(object $period, string $scope, string $id): array
    {
        return ['mode' => 'continuation', 'scope' => $scope, 'resource_id' => $id,
            'period_id' => (string) $period->id, 'period_ends_at' => $period->ends_at,
            'checked_at' => CarbonImmutable::now('UTC')->toIso8601String()];
    }

    private function transaction(): void
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('Entitlement decisions require the Transport school write lock.');
        }
    }
}
