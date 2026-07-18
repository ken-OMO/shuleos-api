<?php

namespace App\Services\TeacherPortal;

use App\Jobs\DeliverTeacherPush;
use App\Models\TeacherPushDelivery;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class TeacherPushService
{
    public function __construct(private TeacherPortalAccessService $access) {}

    public function queue(User $user, string $category, string $title, string $body, ?string $deepLink, string $key): int
    {
        $this->access->teacher($user);
        if (! config('teacher_portal_phase_two.push_enabled')) {
            return 0;
        }
        $count = 0;
        DB::table('teacher_portal_devices')->where('school_id', $user->school_id)->where('user_id', $user->id)->where('push_enabled', true)->whereNull('revoked_at')->get()->each(function ($device) use ($user, $category, $title, $body, $deepLink, $key, &$count) {
            $id = (string) Str::uuid();
            $created = DB::table('teacher_push_deliveries')->insertOrIgnore(['id' => $id, 'school_id' => $user->school_id, 'user_id' => $user->id, 'device_id' => $device->id, 'category' => $category, 'title' => Str::limit(strip_tags($title), 150, ''), 'body' => Str::limit(strip_tags($body), 500, ''), 'deep_link' => $deepLink, 'idempotency_key' => $key, 'status' => 'queued', 'provider' => config('teacher_portal_phase_two.push_provider', 'log'), 'queued_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
            if ($created) {
                DeliverTeacherPush::dispatch($id)->afterCommit();
                $count++;
            }
        });

        return $count;
    }

    public function deliveries(User $user)
    {
        $this->access->teacher($user);

        return TeacherPushDelivery::withoutGlobalScopes()->where('school_id', $user->school_id)->where('user_id', $user->id)->latest()->paginate(30);
    }
}
