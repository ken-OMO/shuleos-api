<?php

declare(strict_types=1);

namespace App\Services\Transport;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use LogicException;

class TransportAuditService
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
            throw new LogicException('Transport audit requires a transaction.');
        }

        if ($actor->getAttribute('school_id') === null) {
            throw new LogicException('Transport audit requires a school actor.');
        }

        $audit = AuditLog::create([
            'school_id' => $actor->school_id,
            'user_id' => $actor->getKey(),
            'module' => 'Transport',
            'action' => $action,
            'table_name' => $table,
            'record_id' => $id,
            'old_values' => $old,
            'new_values' => $new,
            'description' => 'Transport operational mutation.',
            'created_at' => now(),
        ]);

        // An event listener returning false must also abort the mutation.
        if (! $audit->exists) {
            throw new LogicException('Transport audit was not persisted.');
        }
    }
}
