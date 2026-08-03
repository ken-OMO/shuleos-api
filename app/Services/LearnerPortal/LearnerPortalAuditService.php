<?php

namespace App\Services\LearnerPortal;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class LearnerPortalAuditService
{
    public function __construct(private LearnerPortalAccessService $access) {}

    public function record(User $user, string $action, ?string $type = null, ?string $id = null, array $metadata = []): void
    {
        $learner = $this->access->learner($user);
        $safe = collect($metadata)->except(['token', 'password', 'payload', 'storage_id', 'hash'])->take(15)->map(fn ($value) => is_string($value) ? Str::limit(strip_tags($value), 300) : $value)->all();
        DB::table('learner_portal_audit_logs')->insert(['id' => (string) Str::uuid(), 'school_id' => $user->school_id, 'learner_id' => $learner->id, 'actor_user_id' => $user->id, 'action' => $action, 'entity_type' => $type, 'entity_id' => $id, 'safe_metadata' => $safe ? json_encode($safe) : null, 'created_at' => now()]);
    }
}
