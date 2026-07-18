<?php

namespace App\Services\ParentPortal;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ParentPortalAuditService
{
    public function record(User $user, string $action, ?string $learnerId = null, ?string $entityType = null, ?string $entityId = null, array $metadata = []): void
    {
        $safe = collect($metadata)->except(['email', 'phone', 'token', 'device_identifier', 'password', 'path', 'storage_id'])->all();
        DB::table('parent_portal_audit_logs')->insert([
            'id' => (string) Str::uuid(),
            'school_id' => $user->school_id,
            'user_id' => $user->id,
            'learner_id' => $learnerId,
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'safe_metadata' => $safe ? json_encode($safe) : null,
            'created_at' => now(),
        ]);
    }
}
