<?php

namespace App\Services\Auth;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

class AuthenticationAuditService
{
    public function record(Request $request, string $action, ?User $user = null): void
    {
        try {
            if (! Schema::hasTable('audit_logs')) {
                return;
            }

            DB::table('audit_logs')->insert([
                'id' => (string) Str::uuid(),
                'school_id' => $user?->school_id,
                'user_id' => $user?->id,
                'module' => 'Authentication',
                'action' => $action,
                'table_name' => 'users',
                'record_id' => $user?->id,
                'description' => Str::headline($action),
                'old_values' => null,
                'new_values' => null,
                'ip_address' => $request->ip(),
                'user_agent' => Str::limit((string) $request->userAgent(), 500, ''),
                'created_at' => now(),
            ]);
        } catch (Throwable) {
            // Authentication must not fail because optional audit storage is unavailable.
        }
    }
}
