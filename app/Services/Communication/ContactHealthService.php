<?php

namespace App\Services\Communication;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ContactHealthService
{
    public function suppressed(string $schoolId, string $userId, string $channel, string $destination): bool
    {
        return DB::table('communication_contact_health')->where('school_id', $schoolId)->where('user_id', $userId)->where('channel', $channel)->where('destination_hash', hash('sha256', strtolower(trim($destination))))->whereIn('status', ['invalid', 'suppressed', 'opted_out'])->exists();
    }

    public function record(string $schoolId, string $userId, string $channel, string $destination, string $event): void
    {
        $this->recordHash($schoolId, $userId, $channel, hash('sha256', strtolower(trim($destination))), $event);
    }

    public function recordHash(string $schoolId, string $userId, string $channel, string $destinationHash, string $event): void
    {
        abort_unless((bool) preg_match('/^[a-f0-9]{64}$/', $destinationHash), 422, 'Invalid destination hash.');
        $hash = strtolower($destinationHash);
        DB::table('communication_contact_health')->insertOrIgnore(['id' => (string) Str::uuid(), 'school_id' => $schoolId, 'user_id' => $userId, 'channel' => $channel, 'destination_hash' => $hash, 'status' => 'healthy', 'hard_bounce_count' => 0, 'soft_bounce_count' => 0, 'complaint_count' => 0, 'created_at' => now(), 'updated_at' => now()]);
        $row = DB::table('communication_contact_health')->where('school_id', $schoolId)->where('user_id', $userId)->where('channel', $channel)->where('destination_hash', $hash)->first();
        $values = ['updated_at' => now()];
        if (in_array($event, ['delivered', 'sent'], true)) {
            $values += ['last_success_at' => now(), 'status' => $row->status === 'warning' ? 'healthy' : $row->status];
        } elseif ($event === 'hard_bounce' || $event === 'complained') {
            $field = $event === 'complained' ? 'complaint_count' : 'hard_bounce_count';
            $values += [$field => DB::raw($field.' + 1'), 'status' => 'suppressed', 'reason' => $event, 'last_failure_at' => now(), 'suppressed_at' => now()];
        } elseif ($event === 'soft_bounce') {
            $count = $row->soft_bounce_count + 1;
            $values += ['soft_bounce_count' => $count, 'status' => $count >= config('communication.soft_bounce_suppression_threshold', 3) ? 'suppressed' : 'warning', 'reason' => 'soft_bounce', 'last_failure_at' => now(), 'suppressed_at' => $count >= config('communication.soft_bounce_suppression_threshold', 3) ? now() : null];
        }
        DB::table('communication_contact_health')->where('id', $row->id)->update($values);
    }

    public function restore(User $actor, string $id, string $reason): object
    {
        $row = DB::table('communication_contact_health')->where('id', $id)->where('school_id', $actor->school_id)->first();
        abort_unless($row, 404);
        abort_if(trim($reason) === '', 422);
        DB::table('communication_contact_health')->where('id', $id)->update(['status' => 'healthy', 'reason' => 'restored', 'suppressed_at' => null, 'restored_at' => now(), 'restored_by' => $actor->id, 'restoration_reason' => $reason, 'updated_at' => now()]);

        return DB::table('communication_contact_health')->where('id', $id)->first();
    }
}
