<?php

declare(strict_types=1);

namespace App\Services\StudentElectionSubscription;

use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use LogicException;

/** Commercial facts only; callers retain actor authorization and continuation policy. */
final class StudentElectionCommercialEvaluator
{
    public const INCLUDED_FEATURE = 'Student Council Elections';

    public static function enterprisePackages(): Builder
    {
        return DB::table('subscription_packages')->whereRaw("LOWER(TRIM(package_name)) = 'enterprise'");
    }

    public static function includesElections(string $name): bool
    {
        return strtolower(trim($name)) === 'enterprise';
    }

    public function context(string $school): array
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('Commercial checks must share the domain transaction.');
        }
        $s = DB::table('schools')->where('id', $school)->sharedLock()->first();
        $base = DB::table('school_subscriptions')->where('school_id', $school)->orderByDesc('created_at')->orderByDesc('id')->sharedLock()->first();
        $at = CarbonImmutable::now('UTC');
        $date = $at->setTimezone(($s->timezone ?? null) ?: config('app.timezone', 'UTC'))->toDateString();
        $end = $base !== null && $base->status === 'GRACE_PERIOD' ? $base->grace_end_date : ($base->expiry_date ?? null);
        $main = $s !== null && $s->active && ! $s->is_deleted && $s->lifecycle_state === 'active' && $base !== null && $base->package_id !== null && $base->start_date !== null
            && $base->start_date <= $date && $base->expiry_date !== null && in_array($base->status, ['ACTIVE', 'GRACE_PERIOD'], true) && $end !== null && $end >= $date;
        $included = $main && self::enterprisePackages()->where('id', $base->package_id)->sharedLock()->first() !== null;
        $period = DB::table('student_election_addon_periods')->where('school_id', $school)->where('starts_at', '<=', $at->toIso8601String())->where('ends_at', '>', $at->toIso8601String())->first();

        return compact('s', 'base', 'at', 'main', 'period', 'included', 'end');
    }
}
