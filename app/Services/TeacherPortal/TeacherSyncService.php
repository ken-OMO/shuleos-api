<?php

namespace App\Services\TeacherPortal;

use App\Models\User;
use App\Services\Attendance\AttendanceService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class TeacherSyncService
{
    private const ENTITIES = [
        'lesson_plan' => ['table' => 'lesson_plans', 'fields' => ['lesson_date', 'introduction', 'lesson_development', 'conclusion', 'reflection']],
        'lesson_note' => ['table' => 'lesson_notes', 'fields' => ['note_content']],
        'record_of_work' => ['table' => 'records_of_work', 'fields' => ['date_taught', 'content_covered', 'learner_response', 'teacher_reflection', 'status']],
        'homework' => ['table' => 'homework_assignments', 'fields' => ['title', 'instructions', 'due_at', 'allow_late_submission']],
        'preference' => ['table' => 'teacher_dashboard_preferences', 'fields' => ['preferred_language', 'timezone', 'timetable_view']],
    ];

    public function __construct(private TeacherPortalAccessService $access, private AttendanceService $attendance, private MarkEntryBatchService $batches) {}

    public function push(User $user, string $deviceId, array $operations): array
    {
        abort_if(count($operations) > config('teacher_portal_phase_two.sync_batch_limit', 50), 422, 'Too many sync operations.');
        $device = $this->device($user, $deviceId);

        return collect($operations)->map(fn ($operation) => $this->apply($user, $device->id, $operation))->all();
    }

    public function pull(User $user, string $deviceId, ?string $cursor): array
    {
        $this->device($user, $deviceId);
        $since = $cursor ? decrypt($cursor) : now()->subDays(7)->toIso8601String();
        $rows = DB::table('teacher_sync_operations')->where('school_id', $user->school_id)->where('user_id', $user->id)->where('created_at', '>', $since)->orderBy('created_at')->limit(100)->get(['operation_uuid', 'entity_type', 'entity_id', 'status', 'server_version', 'created_at']);
        $now = now()->toIso8601String();

        return ['operations' => $rows, 'cursor' => encrypt($now), 'server_time' => $now];
    }

    public function conflicts(User $user)
    {
        return DB::table('teacher_sync_conflicts')->where('school_id', $user->school_id)->where('user_id', $user->id)->where('status', 'open')->select('id', 'operation_uuid', 'entity_type', 'entity_id', 'client_version', 'server_version', 'safe_server_record', 'status', 'created_at')->paginate(30);
    }

    public function resolve(User $user, string $id): void
    {
        $updated = DB::table('teacher_sync_conflicts')->where('school_id', $user->school_id)->where('user_id', $user->id)->where('id', $id)->where('status', 'open')->update(['status' => 'server_wins', 'resolved_at' => now(), 'updated_at' => now()]);
        abort_unless($updated, 404);
    }

    public function status(User $user, string $deviceId): array
    {
        $this->device($user, $deviceId);

        return ['pending_operations' => DB::table('teacher_sync_operations')->where('user_id', $user->id)->where('status', 'pending')->count(), 'open_conflicts' => DB::table('teacher_sync_conflicts')->where('user_id', $user->id)->where('status', 'open')->count(), 'server_time' => now()->toIso8601String()];
    }

    private function apply(User $user, string $deviceId, array $operation): array
    {
        $existing = DB::table('teacher_sync_operations')->where('user_id', $user->id)->where('operation_uuid', $operation['operation_uuid'])->first();
        if ($existing) {
            return ['operation_uuid' => $existing->operation_uuid, 'status' => $existing->status, 'server_version' => $existing->server_version];
        }
        if ($operation['entity_type'] === 'attendance_draft') {
            return $this->applyAttendance($user, $deviceId, $operation);
        }
        if ($operation['entity_type'] === 'mark_batch') {
            return $this->applyMarkBatch($user, $deviceId, $operation);
        }
        abort_unless(isset(self::ENTITIES[$operation['entity_type']]), 422, 'Unsupported sync entity.');
        $spec = self::ENTITIES[$operation['entity_type']];

        return DB::transaction(function () use ($user, $deviceId, $operation, $spec) {
            $record = DB::table($spec['table'])->where('id', $operation['entity_id'])->where('school_id', $user->school_id)->lockForUpdate()->first();
            abort_unless($record, 404);
            $this->assertOwned($user, $operation['entity_type'], $record);
            $workflow = DB::table('teacher_workflows')->where('school_id', $user->school_id)->where('entity_type', $operation['entity_type'])->where('entity_id', $record->id)->first();
            abort_if($workflow && ! in_array($workflow->state, ['draft', 'changes_requested', 'rejected'], true), 409, 'Submitted work cannot be changed offline.');
            $serverVersion = (int) ($record->sync_version ?? 1);
            if ((int) $operation['base_version'] !== $serverVersion) {
                $conflictId = (string) Str::uuid();
                DB::table('teacher_sync_conflicts')->insert(['id' => $conflictId, 'school_id' => $user->school_id, 'user_id' => $user->id, 'device_id' => $deviceId, 'operation_uuid' => $operation['operation_uuid'], 'entity_type' => $operation['entity_type'], 'entity_id' => $record->id, 'client_version' => $operation['base_version'], 'server_version' => $serverVersion, 'safe_server_record' => json_encode(collect((array) $record)->only($spec['fields'])->all()), 'status' => 'open', 'created_at' => now(), 'updated_at' => now()]);
                $this->log($user, $deviceId, $operation, 'conflict', $serverVersion);

                return ['operation_uuid' => $operation['operation_uuid'], 'status' => 'conflict', 'conflict_id' => $conflictId, 'server_version' => $serverVersion];
            }
            abort_unless(($operation['operation'] ?? 'update') === 'update', 422, 'Offline delete is not supported.');
            $payload = collect($operation['payload'] ?? [])->only($spec['fields'])->all();
            abort_if(! $payload, 422, 'No permitted sync fields supplied.');
            DB::table($spec['table'])->where('id', $record->id)->where('sync_version', $serverVersion)->update($payload + ['sync_version' => $serverVersion + 1, 'updated_at' => now()]);
            $this->log($user, $deviceId, $operation, 'accepted', $serverVersion + 1);

            return ['operation_uuid' => $operation['operation_uuid'], 'status' => 'accepted', 'server_version' => $serverVersion + 1];
        });
    }

    private function applyAttendance(User $user, string $deviceId, array $operation): array
    {
        return DB::transaction(function () use ($user, $deviceId, $operation) {
            $register = $this->attendance->ownQuery($user)->whereKey($operation['entity_id'])->lockForUpdate()->firstOrFail();
            abort_unless($register->status === 'draft', 409, 'Only draft attendance can be synchronized.');
            $version = (int) ($register->sync_version ?? 1);
            if ((int) $operation['base_version'] !== $version) {
                return $this->conflict($user, $deviceId, $operation, $version, ['id' => $register->id, 'status' => $register->status, 'sync_version' => $version]);
            }
            $marks = $operation['payload']['marks'] ?? null;
            abort_unless(is_array($marks) && count($marks) >= 1 && count($marks) <= 100, 422, 'Attendance sync marks must be a bounded array.');
            $this->attendance->save($user, $register->id, $marks);
            DB::table('attendance_registers')->where('id', $register->id)->where('sync_version', $version)->update(['sync_version' => $version + 1, 'updated_at' => now()]);
            $this->log($user, $deviceId, $operation, 'accepted', $version + 1);

            return ['operation_uuid' => $operation['operation_uuid'], 'status' => 'accepted', 'server_version' => $version + 1];
        });
    }

    private function applyMarkBatch(User $user, string $deviceId, array $operation): array
    {
        return DB::transaction(function () use ($user, $deviceId, $operation) {
            $batch = $this->batches->query($user)->whereKey($operation['entity_id'])->lockForUpdate()->firstOrFail();
            abort_unless(in_array($batch->status, ['draft', 'reopened', 'changes_requested'], true), 409, 'Submitted mark batches cannot be synchronized.');
            if ((int) $operation['base_version'] !== (int) $batch->version) {
                return $this->conflict($user, $deviceId, $operation, $batch->version, ['id' => $batch->id, 'status' => $batch->status, 'entered_count' => $batch->entered_count, 'version' => $batch->version]);
            }
            $marks = $operation['payload']['marks'] ?? null;
            abort_unless(is_array($marks) && count($marks) >= 1 && count($marks) <= 100, 422, 'Mark sync entries must be a bounded array.');
            $updated = $this->batches->save($user, $batch->exam_paper_id, $marks, $batch->teacher_assignment_id);
            $this->log($user, $deviceId, $operation, 'accepted', $updated->version);

            return ['operation_uuid' => $operation['operation_uuid'], 'status' => 'accepted', 'server_version' => $updated->version];
        });
    }

    private function conflict(User $user, string $deviceId, array $operation, int $version, array $safeRecord): array
    {
        $id = (string) Str::uuid();
        DB::table('teacher_sync_conflicts')->insert(['id' => $id, 'school_id' => $user->school_id, 'user_id' => $user->id, 'device_id' => $deviceId, 'operation_uuid' => $operation['operation_uuid'], 'entity_type' => $operation['entity_type'], 'entity_id' => $operation['entity_id'], 'client_version' => $operation['base_version'], 'server_version' => $version, 'safe_server_record' => json_encode($safeRecord), 'status' => 'open', 'created_at' => now(), 'updated_at' => now()]);
        $this->log($user, $deviceId, $operation, 'conflict', $version);

        return ['operation_uuid' => $operation['operation_uuid'], 'status' => 'conflict', 'conflict_id' => $id, 'server_version' => $version];
    }

    private function assertOwned(User $user, string $type, object $record): void
    {
        if ($type === 'preference') {
            $teacher = $this->access->teacher($user);
            abort_unless($record->teacher_id === $teacher->id, 403);

            return;
        }
        $assignmentId = match ($type) {
            'lesson_plan', 'homework' => $record->teacher_assignment_id,
            'lesson_note', 'record_of_work' => DB::table('lesson_plans')->where('id', $record->lesson_plan_id)->value('teacher_assignment_id'),
        };
        $this->access->requireAssignment($user, $assignmentId);
    }

    private function device(User $user, string $id): object
    {
        $this->access->teacher($user);
        $device = DB::table('teacher_portal_devices')->where('id', $id)->where('school_id', $user->school_id)->where('user_id', $user->id)->whereNull('revoked_at')->first();
        if (! $device) {
            throw new AuthorizationException('An active owned device is required for synchronization.');
        }

        return $device;
    }

    private function log(User $user, string $deviceId, array $operation, string $status, int $version): void
    {
        DB::table('teacher_sync_operations')->insert(['id' => (string) Str::uuid(), 'school_id' => $user->school_id, 'user_id' => $user->id, 'device_id' => $deviceId, 'operation_uuid' => $operation['operation_uuid'], 'entity_type' => $operation['entity_type'], 'entity_id' => $operation['entity_id'], 'operation' => $operation['operation'] ?? 'update', 'base_version' => $operation['base_version'], 'status' => $status, 'server_version' => $version, 'created_at' => now()]);
    }
}
