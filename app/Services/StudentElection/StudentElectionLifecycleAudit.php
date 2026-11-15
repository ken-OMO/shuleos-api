<?php

declare(strict_types=1);

namespace App\Services\StudentElection;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

class StudentElectionLifecycleAudit
{
    public function record(string $school, ?string $election, ?string $actor, string $action, string $entity, string $id, array $metadata = []): void
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('Election lifecycle audit requires a transaction.');
        }
        abort_unless($actor !== null && DB::table('users')->where('id', $actor)->where('school_id', $school)
            ->where('active', true)->where('is_deleted', false)->exists(), 403, 'A current same-school audit actor is required.');
        if (! DB::table('student_election_audit_logs')->insert([
            'id' => (string) Str::uuid(), 'school_id' => $school, 'election_id' => $election,
            'actor_user_id' => $actor, 'action' => $action, 'entity_type' => $entity, 'entity_id' => $id,
            'metadata' => json_encode($metadata, JSON_THROW_ON_ERROR), 'created_at' => now(),
        ])) {
            throw new LogicException('Election lifecycle audit was not persisted.');
        }
    }
}
