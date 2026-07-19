<?php

namespace App\Services\LearnerPortal;

use App\Models\LearnerPortalDevice;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LearnerDeviceService
{
    public function __construct(private LearnerPortalAccessService $access, private LearnerPortalAuditService $audit) {}

    public function index(User $user)
    {
        $this->access->learner($user);

        return LearnerPortalDevice::withoutGlobalScopes()->where('school_id', $user->school_id)->where('user_id', $user->id)->whereNull('revoked_at')->latest('last_seen_at')->get();
    }

    public function register(User $user, array $data): LearnerPortalDevice
    {
        $learner = $this->access->learner($user);
        $hash = hash('sha256', trim($data['device_identifier']));

        return DB::transaction(function () use ($user, $learner, $data, $hash) {
            $device = LearnerPortalDevice::withoutGlobalScopes()->where('school_id', $user->school_id)->where('user_id', $user->id)->where('device_identifier_hash', $hash)->lockForUpdate()->first();
            if (! $device) {
                $count = LearnerPortalDevice::withoutGlobalScopes()->where('school_id', $user->school_id)->where('user_id', $user->id)->whereNull('revoked_at')->lockForUpdate()->count();
                if ($count >= config('learner_portal_phase_two.device_limit', 3)) {
                    throw ValidationException::withMessages(['device' => 'The learner device limit has been reached.']);
                }
                $device = new LearnerPortalDevice(['id' => (string) Str::uuid(), 'school_id' => $user->school_id, 'user_id' => $user->id, 'learner_id' => $learner->id, 'device_identifier_hash' => $hash]);
            }
            $device->platform = $data['platform'];
            $device->device_name = isset($data['device_name']) ? strip_tags($data['device_name']) : null;
            $device->app_version = $data['app_version'] ?? null;
            $device->push_enabled = false;
            $device->last_seen_at = now();
            $device->revoked_at = null;
            $device->save();
            $this->audit->record($user, 'device_registered', 'learner_portal_device', $device->id, ['platform' => $device->platform]);

            return LearnerPortalDevice::withoutGlobalScopes()->findOrFail($device->id);
        });
    }

    public function owned(User $user, string $id): LearnerPortalDevice
    {
        $this->access->learner($user);
        $device = LearnerPortalDevice::withoutGlobalScopes()->whereKey($id)->where('school_id', $user->school_id)->where('user_id', $user->id)->whereNull('revoked_at')->first();
        if (! $device) {
            throw new AuthorizationException('An active owned learner device is required.');
        }

        return $device;
    }

    public function token(User $user, string $id, ?string $token): LearnerPortalDevice
    {
        $device = $this->owned($user, $id);
        abort_unless(config('learner_portal_phase_two.push_enabled'), 409, 'Learner push is disabled by platform policy.');
        $device->update(['push_token_encrypted' => filled($token) ? Crypt::encryptString($token) : null, 'push_enabled' => filled($token), 'last_seen_at' => now()]);
        $this->audit->record($user, filled($token) ? 'push_token_rotated' : 'push_token_removed', 'learner_portal_device', $id);

        return LearnerPortalDevice::withoutGlobalScopes()->findOrFail($device->id);
    }

    public function revoke(User $user, string $id): void
    {
        $device = $this->owned($user, $id);
        $device->update(['revoked_at' => now(), 'push_enabled' => false, 'push_token_encrypted' => null]);
        $this->audit->record($user, 'device_revoked', 'learner_portal_device', $id);
    }
}
