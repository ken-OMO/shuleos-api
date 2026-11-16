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
        $c = app(StudentElectionCommercialEvaluator::class)->context((string) $school->id);
        if (! $c['main'] || $c['period'] === null) {
            return null;
        }
        $base = $c['base'];
        $period = $c['period'];
        $at = $c['at'];

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
