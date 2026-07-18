<?php

namespace App\Services\TeacherPortal;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class TeacherPortalAuditService
{
    public function record(User $user, string $action, ?string $teacherId = null, ?string $type = null, ?string $id = null, array $metadata = []): void
    {
        $safe = collect($metadata)->except(['email', 'phone', 'token', 'device_identifier', 'password', 'path', 'storage_id'])->all();
        DB::table('teacher_portal_audit_logs')->insert([
            'id' => (string) Str::uuid(), 'school_id' => $user->school_id, 'user_id' => $user->id,
            'teacher_id' => $teacherId, 'action' => $action, 'entity_type' => $type, 'entity_id' => $id,
            'safe_metadata' => $safe ? json_encode($safe) : null, 'created_at' => now(),
        ]);
    }
}
