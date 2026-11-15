<?php

declare(strict_types=1);

namespace App\Services\School;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class SchoolBusinessWriteAccess
{
    // First delivery slice. Other domains retain their existing boundaries.
    private const PREFIXES = [
        'learners', 'guardians', 'teachers', 'teacher-assignments',
        'learning-area-allocations', 'academic-years', 'terms',
        'academic-weeks', 'grades', 'streams',
    ];

    public function covers(Request $request): bool
    {
        return ! $request->isMethodSafe()
            && (in_array($request->segment(2), self::PREFIXES, true)
                || ($request->segment(2) === 'admin' && $request->segment(3) === 'school'));
    }

    public function run(string $schoolId, Closure $operation): mixed
    {
        return DB::transaction(function () use ($schoolId, $operation): mixed {
            // Main-subscription DML takes this same lock through a database trigger.
            // Re-read authoritative state after waiting, not the cached user relation.
            $school = DB::table('schools')->where('id', $schoolId)
                ->lockForUpdate()->first();
            if (! $school || ! $school->active || $school->is_deleted
                || ! in_array($school->lifecycle_state, ['onboarding', 'trial', 'active', 'grace'], true)) {
                throw new AuthorizationException('School business changes are unavailable.');
            }

            $subscription = DB::table('school_subscriptions')->where('school_id', $schoolId)
                ->orderByDesc('created_at')->orderByDesc('id')->first();
            // Read the clock only after all school/subscription serialization waits.
            $date = CarbonImmutable::now('UTC')
                ->setTimezone($school->timezone ?: config('app.timezone', 'UTC'))->toDateString();
            $valid = $subscription && $subscription->start_date !== null
                && $subscription->start_date <= $date && $subscription->expiry_date !== null
                && $subscription->expiry_date >= $subscription->start_date;
            if ($valid) {
                $valid = match ($subscription->status) {
                    'TRIAL' => (bool) $subscription->is_trial && $subscription->expiry_date >= $date,
                    'ACTIVE' => $subscription->package_id !== null && $subscription->expiry_date >= $date,
                    'GRACE_PERIOD' => $subscription->package_id !== null
                        && $subscription->grace_end_date !== null
                        && $subscription->grace_end_date >= $subscription->expiry_date
                        && $subscription->grace_end_date >= $date,
                    default => false,
                };
            }
            if (! $valid) {
                throw new AuthorizationException('An effective school subscription is required for business changes.');
            }

            return $operation();
        });
    }
}
