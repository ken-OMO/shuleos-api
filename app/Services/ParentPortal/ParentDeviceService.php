<?php

namespace App\Services\ParentPortal;

use App\Models\ParentPortalDevice;
use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ParentDeviceService
{
    public function __construct(private ParentPortalAccessService $access, private ParentPortalAuditService $audit) {}

    public function index(User $user)
    {
        $this->access->parent($user);

        return ParentPortalDevice::withoutGlobalScopes()->where('school_id', $user->school_id)->where('user_id', $user->id)->whereNull('revoked_at')->latest('last_seen_at')->get();
    }

    public function register(User $user, array $data): ParentPortalDevice
    {
        $this->access->parent($user);
        $hash = hash('sha256', trim($data['device_identifier']));

        return DB::transaction(function () use ($user, $data, $hash) {
            $existing = ParentPortalDevice::withoutGlobalScopes()->where('school_id', $user->school_id)->where('user_id', $user->id)->where('device_identifier_hash', $hash)->lockForUpdate()->first();
            if (! $existing) {
                $activeCount = ParentPortalDevice::withoutGlobalScopes()->where('school_id', $user->school_id)->where('user_id', $user->id)->whereNull('revoked_at')->lockForUpdate()->count();
                if ($activeCount >= config('parent_portal.device_limit', 5)) {
                    throw ValidationException::withMessages(['device' => 'The parent device limit has been reached.']);
                }
                $existing = new ParentPortalDevice;
                $existing->id = (string) Str::uuid();
                $existing->school_id = $user->school_id;
                $existing->user_id = $user->id;
                $existing->device_identifier_hash = $hash;
            }

            $existing->platform = $data['platform'];
            $existing->app_version = $data['app_version'] ?? null;
            $existing->device_name = isset($data['device_name']) ? strip_tags($data['device_name']) : null;
            if (array_key_exists('push_token', $data)) {
                $existing->push_token_encrypted = filled($data['push_token']) ? Crypt::encryptString($data['push_token']) : null;
            }
            $existing->push_enabled = false;
            $existing->last_seen_at = now();
            $existing->revoked_at = null;
            $existing->save();
            $this->audit->record($user, 'device_registered', null, 'parent_portal_device', $existing->id, ['platform' => $existing->platform]);

            return $existing->fresh();
        });
    }

    public function revoke(User $user, string $id): void
    {
        $this->access->parent($user);
        $device = ParentPortalDevice::withoutGlobalScopes()->where('id', $id)->where('school_id', $user->school_id)->where('user_id', $user->id)->whereNull('revoked_at')->first();
        abort_unless($device, 404);
        $device->update(['revoked_at' => now(), 'push_enabled' => false, 'push_token_encrypted' => null]);
        $this->audit->record($user, 'device_revoked', null, 'parent_portal_device', $device->id);
    }
}
