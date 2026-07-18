<?php

namespace App\Services\TeacherPortal;

use App\Models\TeacherPortalDevice;
use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class TeacherDeviceService
{
    public function __construct(private TeacherPortalAccessService $access, private TeacherPortalAuditService $audit) {}

    public function index(User $user)
    {
        $this->access->teacher($user);

        return TeacherPortalDevice::withoutGlobalScopes()->where('school_id', $user->school_id)->where('user_id', $user->id)->whereNull('revoked_at')->latest('last_seen_at')->get();
    }

    public function register(User $user, array $data): TeacherPortalDevice
    {
        $teacher = $this->access->teacher($user);
        $hash = hash('sha256', trim($data['device_identifier']));

        return DB::transaction(function () use ($user, $teacher, $data, $hash) {
            $device = TeacherPortalDevice::withoutGlobalScopes()->where('school_id', $user->school_id)->where('user_id', $user->id)->where('device_identifier_hash', $hash)->lockForUpdate()->first();
            if (! $device) {
                $count = TeacherPortalDevice::withoutGlobalScopes()->where('school_id', $user->school_id)->where('user_id', $user->id)->whereNull('revoked_at')->lockForUpdate()->count();
                if ($count >= config('teacher_portal.device_limit', 5)) {
                    throw ValidationException::withMessages(['device' => 'The teacher device limit has been reached.']);
                }
                $device = new TeacherPortalDevice(['id' => (string) Str::uuid(), 'school_id' => $user->school_id, 'user_id' => $user->id, 'device_identifier_hash' => $hash]);
            }
            $device->platform = $data['platform'];
            $device->app_version = $data['app_version'] ?? null;
            $device->device_name = isset($data['device_name']) ? strip_tags($data['device_name']) : null;
            if (array_key_exists('push_token', $data)) {
                $device->push_token_encrypted = filled($data['push_token']) ? Crypt::encryptString($data['push_token']) : null;
            }
            $device->push_enabled = false;
            $device->last_seen_at = now();
            $device->revoked_at = null;
            $device->save();
            $this->audit->record($user, 'device_registered', $teacher->id, 'teacher_portal_device', $device->id, ['platform' => $device->platform]);

            return $device->fresh();
        });
    }

    public function revoke(User $user, string $id): void
    {
        $teacher = $this->access->teacher($user);
        $device = TeacherPortalDevice::withoutGlobalScopes()->whereKey($id)->where('school_id', $user->school_id)->where('user_id', $user->id)->whereNull('revoked_at')->first();
        abort_unless($device, 404);
        $device->update(['revoked_at' => now(), 'push_enabled' => false, 'push_token_encrypted' => null]);
        $this->audit->record($user, 'device_revoked', $teacher->id, 'teacher_portal_device', $id);
    }
}
