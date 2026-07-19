<?php

namespace App\Services\ParentPortal;

use App\Jobs\DeliverParentPush;
use App\Models\ParentPushDelivery;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ParentPushService
{
    public function __construct(private ParentPortalAccessService $access) {}

    public function queue(User $user, string $category, string $title, string $body, ?string $deepLink, string $key): int
    {
        if (! config('parent_portal_phase_two.push_enabled')) {
            return 0;
        }
        $this->access->parent($user);
        $count = 0;
        DB::table('parent_portal_devices')->where('school_id', $user->school_id)->where('user_id', $user->id)->where('push_enabled', true)->whereNull('revoked_at')->get()->each(function ($device) use ($user, $category, $title, $body, $deepLink, $key, &$count) {
            $id = (string) Str::uuid();
            $created = DB::table('parent_push_deliveries')->insertOrIgnore([
                'id' => $id, 'school_id' => $user->school_id, 'user_id' => $user->id, 'device_id' => $device->id,
                'category' => $category, 'title' => Str::limit(strip_tags($title), 150, ''),
                'body' => Str::limit(strip_tags($body), 240, ''), 'deep_link' => $deepLink,
                'idempotency_key' => $key, 'status' => 'queued', 'provider' => config('teacher_portal_phase_two.push_provider', 'log'),
                'queued_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
            if ($created) {
                DeliverParentPush::dispatch($id)->afterCommit();
                $count++;
            }
        });

        return $count;
    }

    public function deliveries(User $user)
    {
        $this->access->parent($user);

        return ParentPushDelivery::withoutGlobalScopes()->where('school_id', $user->school_id)->where('user_id', $user->id)->latest()->paginate(30);
    }
}
