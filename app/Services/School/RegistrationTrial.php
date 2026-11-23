<?php

namespace App\Services\School;

use App\Models\User;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class RegistrationTrial
{
    public function __construct(private SchoolBusinessWriteAccess $businessAccess) {}

    public function runActivation(string $schoolId, string $userId, Closure $operation): mixed
    {
        return DB::transaction(function () use ($schoolId, $userId, $operation) {
            // Serialize with subscription changes before handling first-login credentials.
            $school = DB::table('schools')->where('id', $schoolId)->lockForUpdate()->first();
            if ($school && $this->eligible($school) && $this->registration($schoolId, $userId)) {
                return $operation();
            }

            return $this->businessAccess->run($schoolId, $operation);
        });
    }

    public function start(User $user): void
    {
        if (! $user->school_id || ! ($registration = $this->registration($user->school_id, $user->id))) {
            return;
        }

        $school = DB::table('schools')->where('id', $user->school_id)->lockForUpdate()->first();
        if (! $school || ! $this->eligible($school)) {
            return;
        }

        $at = CarbonImmutable::now($school->timezone ?: config('app.timezone', 'UTC'));
        DB::table('school_subscriptions')->insert([
            'id' => (string) Str::uuid(),
            'school_id' => $school->id,
            'package_id' => $registration->requested_package_id,
            'start_date' => $at->toDateString(),
            'expiry_date' => $at->addDays(13)->toDateString(),
            'status' => 'TRIAL',
            'source' => 'TRIAL',
            'is_trial' => true,
            'amount_paid' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('schools')->where('id', $school->id)->update([
            'lifecycle_state' => 'trial',
            'lifecycle_version' => $school->lifecycle_version + 1,
            'updated_at' => now(),
        ]);
    }

    private function eligible(object $school): bool
    {
        return $school->active && ! $school->is_deleted && $school->lifecycle_state === 'onboarding'
            && ! DB::table('school_subscriptions')->where('school_id', $school->id)->exists();
    }

    private function registration(string $schoolId, string $userId): ?object
    {
        return DB::table('school_registration_requests')->where('school_id', $schoolId)
            ->where('initial_admin_user_id', $userId)->where('status', 'approved')
            ->whereNotNull('requested_package_id')->first();
    }
}
