<?php

namespace App\Services\Platform;

use App\Models\AuthenticationChallenge;
use App\Models\School;
use App\Models\User;
use App\Services\Administrator\AdministratorAuditService;
use App\Services\Administrator\AdministratorPortalAccessService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class SchoolAdminInvitationRecovery
{
    public function __construct(
        private AdministratorPortalAccessService $access,
        private AdministratorAuditService $audit,
        private SchoolAdminInvitationDelivery $delivery,
    ) {}

    public function resend(User $actor, string $schoolId, string $userId): array
    {
        $this->access->requirePlatform($actor, 'onboard_schools');

        $payload = DB::transaction(function () use ($actor, $schoolId, $userId) {
            $school = School::query()->whereKey($schoolId)->where('active', true)
                ->where('is_deleted', false)->firstOrFail();
            abort_unless(in_array($school->lifecycle_state, ['onboarding', 'trial', 'active', 'grace'], true), 409, 'School invitations are unavailable.');

            $user = User::query()->whereKey($userId)->where('school_id', $schoolId)
                ->where('active', true)->where('is_deleted', false)
                ->whereHas('role', fn ($q) => $q->whereNull('school_id')->where('system_role', true)
                    ->where('active', true)->where('role_name', 'School Admin'))
                ->lockForUpdate()->firstOrFail();

            abort_if($user->activated_at || ! $user->first_login || ! $user->temporary_password
                || $user->suspended_at || ! filled($user->email), 409, 'Only pending initial administrators can be reinvited.');

            // The user lock serializes cooldown checks and credential rotation.
            $recent = DB::table('audit_logs')->where('action', 'school_admin_invitation_reissued')
                ->where('record_id', $userId)->where('school_id', $schoolId)
                ->where('created_at', '>', now()->subMinute())->exists();
            abort_if($recent || $user->created_at->gt(now()->subMinute()), 429, 'Wait at least 60 seconds before another invitation.');

            $password = Str::random(32).'#9Aa';
            $user->forceFill([
                'password_hash' => Hash::make($password),
                'temporary_password_expires_at' => now()->addDay(),
                'force_password_reset_at' => now(),
                'auth_generation' => $user->auth_generation + 1,
                'invitation_generation' => $user->invitation_generation + 1,
            ])->save();

            AuthenticationChallenge::query()->where('user_id', $userId)->where('school_id', $schoolId)
                ->whereIn('purpose', ['first_login', 'activation'])->whereNull('consumed_at')
                ->update(['consumed_at' => now(), 'updated_at' => now()]);

            $this->audit->record($actor, 'school_admin_invitation_reissued', 'users', $userId, [], [
                'invitation_generation' => $user->invitation_generation,
            ], $schoolId);

            return [
                'school' => ['id' => $schoolId, 'school_name' => $school->school_name],
                'initial_admin' => [
                    'id' => $userId,
                    'email' => $user->email,
                    'username' => $user->username,
                    'temporary_password' => $password,
                    'temporary_password_expires_at' => $user->temporary_password_expires_at,
                ],
                'generation' => $user->invitation_generation,
            ];
        }, 3);

        $result = [
            'school_id' => $schoolId,
            'user_id' => $userId,
            'invitation_generation' => $payload['generation'],
            'invitation' => ['status' => 'pending'],
        ];

        DB::afterCommit(function () use ($payload, &$result) {
            $current = User::query()->whereKey($payload['initial_admin']['id'])
                ->where('school_id', $payload['school']['id'])
                ->where('invitation_generation', $payload['generation'])
                ->whereNull('activated_at')->where('temporary_password', true)
                ->where('active', true)->where('is_deleted', false)->exists();
            $result['invitation']['status'] = $current ? $this->delivery->send($payload) : 'superseded';
        });

        // Unlike initial registration, recovery never returns a plaintext password.
        return $result;
    }
}
