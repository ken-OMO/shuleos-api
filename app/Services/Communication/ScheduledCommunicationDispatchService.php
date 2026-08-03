<?php

namespace App\Services\Communication;

use App\Models\User;
use Illuminate\Support\Facades\DB;

class ScheduledCommunicationDispatchService
{
    public function dispatchDue(CommunicationService $communications, ?string $onlyCommunicationId = null): int
    {
        $sent = 0;

        DB::table('communications')
            ->where('status', 'scheduled')
            ->whereNotNull('scheduled_for')
            ->where('scheduled_for', '<=', now())
            ->when($onlyCommunicationId, fn ($query) => $query->where('id', $onlyCommunicationId))
            ->orderBy('scheduled_for')
            ->limit(config('communication.scheduler_batch_size', 100))
            ->pluck('id')
            ->each(function (string $id) use ($communications, &$sent) {
                try {
                    $communication = DB::table('communications')->where('id', $id)->where('status', 'scheduled')->first();
                    if (! $communication) {
                        return;
                    }

                    $schoolIsActive = DB::table('schools')
                        ->where('id', $communication->school_id)
                        ->where('active', true)
                        ->where('is_deleted', false)
                        ->exists();

                    if (! $schoolIsActive) {
                        return;
                    }

                    $sender = User::whereKey($communication->sender_user_id)
                        ->where('school_id', $communication->school_id)
                        ->where('active', true)
                        ->where('is_deleted', false)
                        ->first();

                    if ($sender) {
                        $communications->send($sender, $id);
                        $sent++;
                    }
                } catch (\Throwable $exception) {
                    report($exception);
                }
            });

        return $sent;
    }
}
