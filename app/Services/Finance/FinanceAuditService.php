<?php

namespace App\Services\Finance;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class FinanceAuditService
{
    public function record(User $user, string $action, string $table, string $record, array $old = [], array $new = []): void
    {
        $redact = fn (array $values) => collect($values)->except(['payer_phone', 'ip_address', 'user_agent', 'password', 'token'])->all();
        DB::table('finance_audit_logs')->insert(['id' => (string) Str::uuid(), 'school_id' => $user->school_id, 'user_id' => $user->id, 'action' => $action, 'table_name' => $table, 'record_id' => $record, 'old_values' => $old ? json_encode($redact($old)) : null, 'new_values' => $new ? json_encode($redact($new)) : null, 'created_at' => now()]);
    }
}
