<?php

namespace App\Services\Administrator\Operations;

use App\Jobs\RunAdministratorScheduledTask;
use App\Models\User;
use App\Services\Administrator\AdministratorAuditService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AdministratorSchedulerService
{
    public function __construct(private AdministratorOperationsAccessService $access, private AdministratorAuditService $audit) {}

    public function summary(User $user): array
    {
        $this->access->platform($user, 'view_scheduler_operations');
        $threshold = now()->subMinutes((int) config('administrator_operations.heartbeat_stale_minutes'));
        $rows = $this->tasks($user);

        return ['status' => collect($rows)->contains(fn ($task) => $task['status'] !== 'healthy') ? 'warning' : 'healthy', 'stale_threshold_minutes' => (int) config('administrator_operations.heartbeat_stale_minutes'), 'tasks' => $rows, 'authoritative' => true];
    }

    public function tasks(User $user): array
    {
        $this->access->platform($user, 'view_scheduler_operations');
        $threshold = now()->subMinutes((int) config('administrator_operations.heartbeat_stale_minutes'));

        return collect(config('administrator_operations.scheduler_tasks'))->map(function ($command, $key) use ($threshold) {
            $row = DB::table('administrator_scheduler_heartbeats')->where('task_key', $key)->first();
            $fresh = $row?->last_completed_at && Carbon::parse($row->last_completed_at)->gte($threshold);

            return ['task_key' => $key, 'status' => $fresh ? ($row->status === 'failed' ? 'failed' : 'healthy') : 'unknown_or_stale', 'last_started_at' => $row?->last_started_at, 'last_completed_at' => $row?->last_completed_at, 'last_failed_at' => $row?->last_failed_at, 'duration_ms' => $row?->duration_ms, 'running' => (bool) $row?->active_run_id];
        })->values()->all();
    }

    public function task(User $user, string $key): array
    {
        $task = collect($this->tasks($user))->firstWhere('task_key', $key);

        return $task ?: abort(404);
    }

    public function run(User $user, string $key): array
    {
        $this->access->platform($user, 'run_allowlisted_scheduler_tasks');
        if (! array_key_exists($key, config('administrator_operations.scheduler_tasks'))) {
            throw ValidationException::withMessages(['task' => 'Scheduler task is not allowlisted.']);
        }
        if (DB::table('administrator_scheduler_runs')->where('task_key', $key)->whereIn('status', ['queued', 'running'])->exists()) {
            throw ValidationException::withMessages(['task' => 'This task already has an active run.']);
        }
        $id = (string) Str::uuid();
        DB::table('administrator_scheduler_runs')->insert(['id' => $id, 'task_key' => $key, 'status' => 'queued', 'requested_by' => $user->id, 'manual' => true, 'created_at' => now(), 'updated_at' => now()]);
        RunAdministratorScheduledTask::dispatch($id, $key)->afterCommit();
        $this->audit->record($user, 'administrator_scheduler_task_queued', 'administrator_scheduler_runs', $id, [], ['task_key' => $key]);

        return ['id' => $id, 'task_key' => $key, 'status' => 'queued'];
    }
}
