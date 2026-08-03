<?php

namespace App\Services\Communication;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CommunicationAuditService
{
    public function record(User $actor, string $action, string $entityType, ?string $entityId = null, ?string $communicationId = null, array $metadata = []): void
    {
        $safe = collect($metadata)->except(['body', 'email', 'emails', 'phone', 'phones', 'recipient_users', 'password', 'token', 'credentials'])->all();
        DB::table('communication_audit_logs')->insert(['id' => (string) Str::uuid(), 'school_id' => $actor->school_id, 'communication_id' => $communicationId, 'actor_user_id' => $actor->id, 'action' => $action, 'entity_type' => $entityType, 'entity_id' => $entityId, 'metadata' => $safe ? json_encode($safe) : null, 'created_at' => now()]);
    }
}
