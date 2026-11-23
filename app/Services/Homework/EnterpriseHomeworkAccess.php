<?php

declare(strict_types=1);

namespace App\Services\Homework;

use Illuminate\Support\Facades\DB;

final class EnterpriseHomeworkAccess
{
    public const INCLUDED_FEATURE = 'Online Homework';

    public static function includesHomework(?string $packageName): bool
    {
        return strtolower(trim($packageName ?? '')) === 'enterprise';
    }

    public function allows(?string $schoolId): bool
    {
        if ($schoolId === null) {
            return false;
        }

        $school = DB::table('schools')->where('id', $schoolId)->where('active', true)->where('is_deleted', false)->first();
        if ($school === null || ! in_array($school->lifecycle_state, ['trial', 'active', 'grace'], true)) {
            return false;
        }

        $subscription = DB::table('school_subscriptions as subscription')
            ->leftJoin('subscription_packages as package', 'package.id', '=', 'subscription.package_id')
            ->where('subscription.school_id', $schoolId)
            ->orderByDesc('subscription.created_at')->orderByDesc('subscription.id')
            ->first(['subscription.*', 'package.package_name']);

        $today = now($school->timezone ?: config('app.timezone'))->toDateString();
        if ($subscription === null || ! self::includesHomework($subscription->package_name)
            || $subscription->start_date === null || $subscription->start_date > $today
            || $subscription->expiry_date === null || $subscription->expiry_date < $subscription->start_date) {
            return false;
        }

        return match ($subscription->status) {
            'TRIAL' => (bool) $subscription->is_trial && $subscription->expiry_date >= $today,
            'ACTIVE' => $subscription->expiry_date >= $today,
            'GRACE_PERIOD' => $subscription->grace_end_date !== null
                && $subscription->grace_end_date >= $subscription->expiry_date && $subscription->grace_end_date >= $today,
            default => false,
        };
    }

    public function authorize(?string $schoolId): void
    {
        abort_unless($this->allows($schoolId), 403, 'Online Homework requires an eligible Enterprise subscription.');
    }
}
