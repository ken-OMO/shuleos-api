<?php

namespace App\Services\Administrator;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AdministratorAuditService
{
    public function record(User $actor, string $action, string $table, ?string $recordId, array $old = [], array $new = [], ?string $targetSchoolId = null): void
    {
        $blocked = ['password', 'password_hash', 'password_reset_token', 'token', 'secret', 'storage_id', 'push_token', 'push_token_encrypted', 'payload', 'exception'];
        $sanitize = fn (array $data) => collect($data)->except($blocked)->map(fn ($value) => is_string($value) ? Str::limit(strip_tags($value), 1000, '') : $value)->all();
        DB::table('audit_logs')->insert([
            'id' => (string) Str::uuid(), 'school_id' => $targetSchoolId ?: $actor->school_id, 'user_id' => $actor->id,
            'module' => 'Administrator Portal', 'action' => $action, 'table_name' => $table, 'record_id' => $recordId,
            'description' => Str::headline($action), 'old_values' => $old ? json_encode($sanitize($old)) : null,
            'new_values' => $new ? json_encode($sanitize($new)) : null, 'ip_address' => null, 'user_agent' => null, 'created_at' => now(),
        ]);
    }
}
