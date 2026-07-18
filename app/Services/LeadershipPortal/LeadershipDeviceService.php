<?php

namespace App\Services\LeadershipPortal;

use App\Models\LeadershipPortalDevice;
use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LeadershipDeviceService
{
    public function __construct(
        private LeadershipPortalAccessService $access,
        private LeadershipPortalAuditService $audit,
    ) {}

    public function index(User $user)
    {
        $this->access->require($user, 'manage_leadership_devices');

        return LeadershipPortalDevice::withoutGlobalScopes()
            ->where('school_id', $user->school_id)
            ->where('user_id', $user->id)
            ->whereNull('revoked_at')
            ->latest('last_seen_at')
            ->get();
    }

    public function register(User $user, array $data): LeadershipPortalDevice
    {
        $this->access->require($user, 'manage_leadership_devices');
        $hash = hash('sha256', trim($data['device_identifier']));

        return DB::transaction(function () use ($user, $data, $hash) {
            $device = LeadershipPortalDevice::withoutGlobalScopes()
                ->where('school_id', $user->school_id)
                ->where('user_id', $user->id)
                ->where('device_identifier_hash', $hash)
                ->lockForUpdate()
                ->first();

            if (! $device) {
                $count = LeadershipPortalDevice::withoutGlobalScopes()
                    ->where('school_id', $user->school_id)
                    ->where('user_id', $user->id)
                    ->whereNull('revoked_at')
                    ->lockForUpdate()
                    ->count();
                if ($count >= config('leadership_portal_phase_two.device_limit', 5)) {
                    throw ValidationException::withMessages(['device' => 'The leadership device limit has been reached.']);
                }
                $device = new LeadershipPortalDevice([
                    'id' => (string) Str::uuid(),
                    'school_id' => $user->school_id,
                    'user_id' => $user->id,
                    'device_identifier_hash' => $hash,
                ]);
            }

            $device->platform = $data['platform'];
            $device->device_name = isset($data['device_name']) ? strip_tags($data['device_name']) : null;
            $device->app_version = $data['app_version'] ?? null;
            $device->push_token_encrypted = filled($data['push_token'] ?? null)
                ? Crypt::encryptString($data['push_token'])
                : $device->push_token_encrypted;
            $device->push_enabled = false;
            $device->last_seen_at = now();
            $device->revoked_at = null;
            $device->save();
            $this->audit->record($user, 'device_registered', 'leadership_portal_device', $device->id, ['platform' => $device->platform]);

            return LeadershipPortalDevice::withoutGlobalScopes()->findOrFail($device->id);
        });
    }

    public function revoke(User $user, string $id): void
    {
        $this->access->require($user, 'manage_leadership_devices');
        $device = LeadershipPortalDevice::withoutGlobalScopes()
            ->whereKey($id)
            ->where('school_id', $user->school_id)
            ->where('user_id', $user->id)
            ->whereNull('revoked_at')
            ->firstOrFail();
        $device->update(['revoked_at' => now(), 'push_enabled' => false, 'push_token_encrypted' => null]);
        $this->audit->record($user, 'device_revoked', 'leadership_portal_device', $device->id);
    }
}
