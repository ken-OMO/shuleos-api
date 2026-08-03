<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class RunAdministratorScheduledTask implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public string $runId, public string $taskKey)
    {
        $this->onQueue('default');
    }

    public function handle(): void
    {
        $command = config('administrator_operations.scheduler_tasks.'.$this->taskKey);
        abort_unless(is_string($command) && in_array($this->taskKey, array_keys(config('administrator_operations.scheduler_tasks', [])), true), 422, 'Scheduler task is not allowlisted.');
        $started = microtime(true);
        DB::table('administrator_scheduler_runs')->where('id', $this->runId)->update(['status' => 'running', 'started_at' => now(), 'updated_at' => now()]);
        DB::table('administrator_scheduler_heartbeats')->updateOrInsert(['task_key' => $this->taskKey], ['id' => $this->heartbeatId(), 'status' => 'running', 'last_started_at' => now(), 'active_run_id' => $this->runId, 'updated_at' => now(), 'created_at' => now()]);
        try {
            Artisan::call($command);
            $duration = (int) ((microtime(true) - $started) * 1000);
            DB::table('administrator_scheduler_runs')->where('id', $this->runId)->update(['status' => 'completed', 'completed_at' => now(), 'safe_message' => 'Allowlisted task completed.', 'updated_at' => now()]);
            DB::table('administrator_scheduler_heartbeats')->where('task_key', $this->taskKey)->update(['status' => 'healthy', 'last_completed_at' => now(), 'duration_ms' => $duration, 'safe_message' => 'Allowlisted task completed.', 'active_run_id' => null, 'updated_at' => now()]);
        } catch (\Throwable $exception) {
            DB::table('administrator_scheduler_runs')->where('id', $this->runId)->update(['status' => 'failed', 'completed_at' => now(), 'safe_message' => 'Allowlisted task failed.', 'updated_at' => now()]);
            DB::table('administrator_scheduler_heartbeats')->where('task_key', $this->taskKey)->update(['status' => 'failed', 'last_failed_at' => now(), 'safe_message' => 'Allowlisted task failed.', 'active_run_id' => null, 'updated_at' => now()]);
            throw $exception;
        }
    }

    private function heartbeatId(): string
    {
        return DB::table('administrator_scheduler_heartbeats')->where('task_key', $this->taskKey)->value('id') ?: (string) Str::uuid();
    }
}
