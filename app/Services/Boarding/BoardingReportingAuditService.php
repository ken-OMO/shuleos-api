<?php

declare(strict_types=1);

namespace App\Services\Boarding;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use LogicException;

class BoardingReportingAuditService
{
    public function record(
        User $actor,
        string $action,
        string $table,
        string $id,
        array $old = [],
        array $new = []
    ): void {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('Reporting audit requires a transaction.');
        }

        AuditLog::create([
            'school_id' => $actor->school_id,
            'user_id' => $actor->id,
            'module' => 'Boarding Reporting',
            'action' => $action,
            'table_name' => $table,
            'record_id' => $id,
            'old_values' => $old,
            'new_values' => $new,
            'description' => 'Boarding reporting lifecycle operation.',
            'created_at' => now(),
        ]);
    }
}
