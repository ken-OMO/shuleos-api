<?php

namespace App\Services\Communication;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class RecurringCommunicationService
{
    public function create(User $user, array $data): object
    {
        $communication = DB::table('communications')->where('id', $data['communication_id'])->where('school_id', $user->school_id)->where('sender_user_id', $user->id)->where('status', 'approved')->whereNotNull('approved_at')->first();
        abort_unless($communication, 404);
        $start = CarbonImmutable::parse($data['starts_at'], $data['timezone'] ?? 'Africa/Nairobi');
        abort_if($start->lessThan(now()->addMinutes(config('communication.recurrence_minimum_minutes', 60))), 422, 'Recurring schedule starts too soon.');
        $id = (string) Str::uuid();
        DB::table('recurring_communication_schedules')->insert(['id' => $id, 'school_id' => $user->school_id, 'communication_id' => $communication->id, 'created_by' => $user->id, 'frequency' => $data['frequency'], 'selected_weekdays' => isset($data['selected_weekdays']) ? json_encode($data['selected_weekdays']) : null, 'maximum_occurrences' => $data['maximum_occurrences'] ?? null, 'occurrences_dispatched' => 0, 'starts_at' => $start->utc(), 'ends_at' => isset($data['ends_at']) ? CarbonImmutable::parse($data['ends_at'], $data['timezone'] ?? 'Africa/Nairobi')->utc() : null, 'next_run_at' => $start->utc(), 'timezone' => $data['timezone'] ?? 'Africa/Nairobi', 'missed_run_policy' => $data['missed_run_policy'] ?? 'skip', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);

        return $this->find($user, $id);
    }

    public function update(User $user, string $id, array $data): object
    {
        $row = $this->find($user, $id);
        abort_unless(in_array($row->status, ['active', 'paused'], true), 409);
        DB::table('recurring_communication_schedules')->where('id', $id)->update(collect($data)->only(['maximum_occurrences', 'ends_at', 'missed_run_policy'])->all() + ['updated_at' => now()]);

        return $this->find($user, $id);
    }

    public function transition(User $user, string $id, string $action): object
    {
        $row = $this->find($user, $id);
        $status = match ($action) {
            'pause' => 'paused', 'resume' => 'active', 'cancel' => 'cancelled', default => throw ValidationException::withMessages(['action' => 'Invalid recurring action.'])
        };
        abort_if($row->status === 'cancelled', 409);
        DB::table('recurring_communication_schedules')->where('id', $id)->update(['status' => $status, 'updated_at' => now()]);

        return $this->find($user, $id);
    }

    public function dispatchDue(CommunicationService $communications): int
    {
        $count = 0;
        $ids = DB::table('recurring_communication_schedules')->where('status', 'active')->where('next_run_at', '<=', now())->orderBy('next_run_at')->limit(config('communication.scheduler_batch_size', 100))->pluck('id');
        foreach ($ids as $id) {
            DB::transaction(function () use ($id, $communications, &$count) {
                $schedule = DB::table('recurring_communication_schedules')->where('id', $id)->where('status', 'active')->lockForUpdate()->first();
                if (! $schedule || ($schedule->ends_at && CarbonImmutable::parse($schedule->ends_at)->isPast()) || ($schedule->maximum_occurrences && $schedule->occurrences_dispatched >= $schedule->maximum_occurrences)) {
                    if ($schedule) {
                        DB::table('recurring_communication_schedules')->where('id', $id)->update(['status' => 'completed', 'updated_at' => now()]);
                    }

                    return;
                }
                $runId = (string) Str::uuid();
                $inserted = DB::table('recurring_communication_runs')->insertOrIgnore(['id' => $runId, 'school_id' => $schedule->school_id, 'schedule_id' => $id, 'communication_id' => null, 'scheduled_for' => $schedule->next_run_at, 'status' => 'pending', 'created_at' => now()]);
                if (! $inserted) {
                    return;
                }
                $base = DB::table('communications')->where('id', $schedule->communication_id)->where('school_id', $schedule->school_id)->firstOrFail();
                $schoolIsActive = DB::table('schools')->where('id', $schedule->school_id)->where('active', true)->where('is_deleted', false)->exists();
                if (! $schoolIsActive) {
                    DB::table('recurring_communication_runs')->where('id', $runId)->update(['status' => 'skipped', 'safe_failure_reason' => 'School unavailable.']);

                    return;
                }
                $sender = User::whereKey($schedule->created_by)->where('school_id', $schedule->school_id)->where('active', true)->where('is_deleted', false)->first();
                if (! $sender) {
                    DB::table('recurring_communication_runs')->where('id', $runId)->update(['status' => 'failed', 'safe_failure_reason' => 'Sender unavailable.']);

                    return;
                }
                $communicationId = (string) Str::uuid();
                DB::table('communications')->insert(['id' => $communicationId, 'school_id' => $base->school_id, 'sender_user_id' => $sender->id, 'communication_type' => $base->communication_type, 'category' => $base->category, 'priority' => $base->priority, 'subject' => $base->subject, 'body' => $base->body, 'status' => 'approved', 'channels' => $base->channels, 'requires_approval' => false, 'risk_level' => $base->risk_level, 'approved_by' => $base->approved_by, 'approved_at' => $base->approved_at ?: now(), 'metadata' => json_encode(['recurring_schedule_id' => $id, 'recurring_run_id' => $runId]), 'recipient_count' => 0, 'created_at' => now(), 'updated_at' => now()]);
                foreach (DB::table('communication_targets')->where('communication_id', $base->id)->where('school_id', $base->school_id)->get() as $target) {
                    DB::table('communication_targets')->insert(['id' => (string) Str::uuid(), 'school_id' => $base->school_id, 'communication_id' => $communicationId, 'target_type' => $target->target_type, 'options' => $target->options, 'created_at' => now()]);
                }
                $communications->send($sender, $communicationId);
                $next = $this->next(CarbonImmutable::parse($schedule->next_run_at), $schedule->frequency, $schedule->selected_weekdays ? json_decode($schedule->selected_weekdays, true) : []);
                DB::table('recurring_communication_runs')->where('id', $runId)->update(['communication_id' => $communicationId, 'status' => 'sent', 'dispatched_at' => now()]);
                DB::table('recurring_communication_schedules')->where('id', $id)->update(['occurrences_dispatched' => DB::raw('occurrences_dispatched + 1'), 'next_run_at' => $next, 'updated_at' => now()]);
                $count++;
            });
        }

        return $count;
    }

    public function next(CarbonImmutable $from, string $frequency, array $weekdays = []): CarbonImmutable
    {
        if ($frequency === 'daily') {
            return $from->addDay();
        }
        if ($frequency === 'weekly') {
            return $from->addWeek();
        }
        if ($frequency === 'monthly') {
            return $from->addMonthNoOverflow();
        }
        if ($frequency === 'selected_weekdays' && $weekdays) {
            $next = $from;
            do {
                $next = $next->addDay();
            } while (! in_array($next->dayOfWeekIso, $weekdays, true));

            return $next;
        }

        throw ValidationException::withMessages(['frequency' => 'Unsupported recurrence frequency.']);
    }

    public function find(User $user, string $id): object
    {
        $row = DB::table('recurring_communication_schedules')->where('id', $id)->where('school_id', $user->school_id)->first();
        abort_unless($row, 404);

        return $row;
    }
}
