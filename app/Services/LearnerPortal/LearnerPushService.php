<?php

namespace App\Services\LearnerPortal;

use App\Jobs\DeliverLearnerPush;
use App\Models\LearnerPushDelivery;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class LearnerPushService
{
    public function __construct(private LearnerPortalAccessService $access) {}

    public function queue(User $user, string $category, string $title, string $body, ?string $deepLink, string $key): int
    {
        $learner = $this->access->learner($user);
        if (! config('learner_portal_phase_two.push_enabled')) {
            return 0;
        }
        $count = 0;
        DB::table('learner_portal_devices')->where('school_id', $user->school_id)->where('user_id', $user->id)->where('push_enabled', true)->whereNull('revoked_at')->get()->each(function ($device) use ($user, $learner, $category, $title, $body, $deepLink, $key, &$count) {
            $id = (string) Str::uuid();
            $created = DB::table('learner_push_deliveries')->insertOrIgnore(['id' => $id, 'school_id' => $user->school_id, 'user_id' => $user->id, 'learner_id' => $learner->id, 'device_id' => $device->id, 'category' => $category, 'title' => Str::limit(strip_tags($title), 150, ''), 'body' => Str::limit(strip_tags($body), 300, ''), 'deep_link' => $deepLink, 'idempotency_key' => $key, 'status' => 'queued', 'provider' => config('learner_portal_phase_two.push_provider', 'log'), 'queued_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
            if ($created) {
                DeliverLearnerPush::dispatch($id)->afterCommit();
                $count++;
            }
        });

        return $count;
    }

    public function deliveries(User $user)
    {
        $learner = $this->access->learner($user);

        return LearnerPushDelivery::withoutGlobalScopes()->where('school_id', $user->school_id)->where('learner_id', $learner->id)->latest()->paginate(20);
    }
}
