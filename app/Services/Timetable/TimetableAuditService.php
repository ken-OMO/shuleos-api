<?php

namespace App\Services\Timetable;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class TimetableAuditService
{
    public function record(User $user, string $timetable, string $action, array $context = [], ?string $reason = null): void
    {
        DB::table('timetable_audit_logs')->insert(['id' => (string) Str::uuid(), 'school_id' => $user->school_id, 'timetable_id' => $timetable, 'timetable_entry_id' => $context['entry_id'] ?? null, 'generation_run_id' => $context['run_id'] ?? null, 'substitution_id' => $context['substitution_id'] ?? null, 'actor_user_id' => $user->id, 'action' => $action, 'previous_values' => isset($context['previous']) ? json_encode($context['previous']) : null, 'new_values' => isset($context['new']) ? json_encode($context['new']) : null, 'reason' => $reason, 'created_at' => now()]);
    }
}
