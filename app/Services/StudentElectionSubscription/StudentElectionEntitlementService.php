<?php

declare(strict_types=1);

namespace App\Services\StudentElectionSubscription;

use App\Models\User;
use App\Services\Auth\AuthContextService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use LogicException;

/** Commercial decision only. Callers retain authentication, permission and election-scope checks. */
final class StudentElectionEntitlementService
{
    public function normal(User $authenticated): ?array
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('Entitlement decisions require the caller transaction.');
        }
        abort_unless($authenticated->getAttribute('school_id') !== null, 403);
        // Issuance uses school UPDATE; normal decisions use SHARE to preserve independent voter concurrency.
        $school = DB::table('schools')->where('id', $authenticated->school_id)->sharedLock()->first();
        $actor = User::withoutGlobalScopes()->whereKey($authenticated->id)->where('school_id', $authenticated->school_id)->sharedLock()->first();
        abort_unless($actor !== null, 403);
        app(AuthContextService::class)->assertAccessible($actor);
        if ($school === null || ! $school->active || $school->is_deleted || $school->lifecycle_state !== 'active') {
            return null;
        }
        $base = DB::table('school_subscriptions')->where('school_id', $school->id)
            ->orderByDesc('created_at')->orderByDesc('id')->sharedLock()->first();
        // Never use a clock sampled before the subscription lock wait.
        $at = CarbonImmutable::now('UTC');
        $period = DB::table('student_election_addon_periods')->where('school_id', $school->id)
            ->where('starts_at', '<=', $at->toIso8601String())->where('ends_at', '>', $at->toIso8601String())->first();
        if ($period === null || $base === null || $base->package_id === null || $base->start_date === null || $base->expiry_date === null) {
            return null;
        }
        $date = $at->setTimezone($school->timezone ?: config('app.timezone', 'UTC'))->toDateString();
        $end = $base->status === 'GRACE_PERIOD' ? $base->grace_end_date : $base->expiry_date;
        if ($base->start_date > $date || ! in_array($base->status, ['ACTIVE', 'GRACE_PERIOD'], true) || $end === null || $end < $date) {
            return null;
        }

        return ['mode' => 'normal', 'period_id' => (string) $period->id, 'main_subscription_id' => (string) $base->id,
            'checked_at' => $at->toIso8601String(), 'ends_at' => CarbonImmutable::parse($period->ends_at)->utc()->toIso8601String()];
    }

    public function requireNormal(User $actor): array
    {
        $basis = $this->normal($actor);
        abort_unless($basis !== null, 403, 'An effective Elections add-on and eligible main subscription are required.');

        return $basis;
    }
}
