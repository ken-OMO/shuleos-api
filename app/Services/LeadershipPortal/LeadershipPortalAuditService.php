<?php

namespace App\Services\LeadershipPortal;

use App\Models\LeadershipPortalAuditLog;
use App\Models\User;
use Illuminate\Support\Str;

class LeadershipPortalAuditService
{
    public function record(User $actor, string $action, ?string $entityType = null, ?string $entityId = null, array $metadata = []): void
    {
        $safe = collect($metadata)
            ->except(['password', 'token', 'push_token', 'authorization', 'request', 'payload'])
            ->take(20)
            ->map(fn ($value) => is_string($value) ? Str::limit(strip_tags($value), 300) : $value)
            ->all();

        LeadershipPortalAuditLog::withoutGlobalScopes()->create([
            'id' => (string) Str::uuid(),
            'school_id' => $actor->school_id,
            'actor_user_id' => $actor->id,
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'safe_metadata' => $safe ?: null,
            'created_at' => now(),
        ]);
    }
}
