<?php

namespace App\Services\Administrator\Operations;

use App\Models\User;
use App\Services\Administrator\AdministratorAuditService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class AdministratorQueueService
{
    public function __construct(private AdministratorOperationsAccessService $access, private AdministratorAuditService $audit) {}

    public function summary(User $user): array
    {
        $this->access->platform($user, 'view_queue_operations');

        return ['pending' => Schema::hasTable('jobs') ? DB::table('jobs')->count() : 0, 'failed' => Schema::hasTable('failed_jobs') ? DB::table('failed_jobs')->count() : 0, 'queues' => config('administrator_operations.queue_names'), 'payloads_exposed' => false, 'bulk_flush_available' => false];
    }

    public function jobs(User $user): mixed
    {
        $this->access->platform($user, 'view_queue_operations');
        if (! Schema::hasTable('jobs')) {
            return [];
        }

        return DB::table('jobs')->whereIn('queue', config('administrator_operations.queue_names'))->select('id', 'queue', 'attempts', 'reserved_at', 'available_at', 'created_at')->orderBy('id')->limit(100)->get();
    }

    public function failed(User $user, ?string $id = null): mixed
    {
        $this->access->platform($user, 'view_queue_operations');
        $query = DB::table('failed_jobs')->select('uuid', 'connection', 'queue', 'failed_at');
        if ($id) {
            $row = $query->where('uuid', $id)->firstOrFail();
            $internal = DB::table('failed_jobs')->where('uuid', $id)->first();

            return (array) $row + ['job_label' => $this->jobLabel($internal->payload), 'safe_error' => $this->safeError($internal->exception)];
        }

        return $query->whereIn('queue', config('administrator_operations.queue_names'))->latest('failed_at')->limit(100)->get();
    }

    public function retry(User $user, string $id): array
    {
        $this->access->platform($user, 'retry_failed_jobs');
        $job = DB::table('failed_jobs')->where('uuid', $id)->firstOrFail();
        if (! in_array($job->queue, config('administrator_operations.queue_names'), true) || ! array_key_exists($job->connection, config('queue.connections', []))) {
            throw ValidationException::withMessages(['job' => 'Failed job queue or connection is not allowlisted.']);
        }
        Queue::connection($job->connection)->pushRaw($job->payload, $job->queue);
        DB::table('failed_jobs')->where('uuid', $id)->delete();
        $this->audit->record($user, 'administrator_failed_job_retried', 'failed_jobs', null, [], ['job_uuid' => $id, 'queue' => $job->queue, 'job_label' => $this->jobLabel($job->payload)]);

        return ['id' => $id, 'status' => 'requeued', 'queue' => $job->queue];
    }

    public function forget(User $user, string $id, ?string $confirmation): array
    {
        $this->access->platform($user, 'forget_failed_jobs');
        $this->access->confirm($user, $confirmation, 'FORGET FAILED JOB');
        $job = DB::table('failed_jobs')->where('uuid', $id)->firstOrFail();
        DB::table('failed_jobs')->where('uuid', $id)->delete();
        $this->audit->record($user, 'administrator_failed_job_forgotten', 'failed_jobs', null, [], ['job_uuid' => $id, 'queue' => $job->queue, 'job_label' => $this->jobLabel($job->payload)]);

        return ['id' => $id, 'status' => 'forgotten'];
    }

    private function jobLabel(string $payload): string
    {
        $decoded = json_decode($payload, true);
        $label = $decoded['displayName'] ?? $decoded['job'] ?? 'Unknown job';

        return class_basename(is_string($label) ? $label : 'Unknown job');
    }

    private function safeError(string $exception): string
    {
        $line = strtok($exception, "\n") ?: 'Job failed.';
        $line = preg_replace('/(password|token|secret|authorization|api[_-]?key)\s*[=:]\s*[^\s,;]+/i', '$1=[redacted]', $line);

        return str($line)->limit(300, '')->toString();
    }
}
