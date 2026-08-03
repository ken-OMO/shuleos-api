<?php

namespace App\Services\LearnerPortal;

use App\Models\User;
use App\Services\Homework\HomeworkLearnerService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class LearnerSyncService
{
    private const ENTITIES = [
        'homework_submission_draft' => ['table' => 'homework_submissions', 'fields' => ['text_response', 'external_url', 'learner_comment']],
        'preference' => ['table' => 'learner_dashboard_preferences', 'fields' => ['preferred_language', 'timezone', 'dashboard_widgets', 'notification_preferences', 'accessibility_preferences', 'digest_frequency', 'quiet_hours_start', 'quiet_hours_end']],
        'profile_draft' => ['table' => 'learner_dashboard_preferences', 'fields' => ['display_name', 'preferred_language', 'timezone']],
        'offline_resource' => ['custom' => true],
        'notification_state' => ['custom' => true],
        'announcement_read' => ['custom' => true],
    ];

    public function __construct(private LearnerPortalAccessService $access, private LearnerDeviceService $devices, private LearnerOfflineResourceService $offline, private HomeworkLearnerService $homework) {}

    public function push(User $user, string $deviceId, array $operations): array
    {
        abort_if(count($operations) > config('learner_portal_phase_two.sync_batch_limit', 40), 422, 'Too many sync operations.');
        abort_if(strlen(json_encode($operations, JSON_THROW_ON_ERROR)) > config('learner_portal_phase_two.sync_payload_bytes', 65536), 413, 'The learner sync payload is too large.');
        $device = $this->devices->owned($user, $deviceId);

        return collect($operations)->map(fn ($operation) => $this->apply($user, $device->id, $operation))->all();
    }

    public function pull(User $user, string $deviceId, ?string $cursor): array
    {
        $this->devices->owned($user, $deviceId);
        $since = $cursor ? decrypt($cursor) : now()->subDays(7)->toIso8601String();
        $rows = DB::table('learner_sync_operations')->where('school_id', $user->school_id)->where('user_id', $user->id)->where('created_at', '>', $since)->orderBy('created_at')->limit(config('learner_portal_phase_two.sync_pull_limit', 100))->get(['operation_uuid', 'entity_type', 'entity_id', 'status', 'server_version', 'created_at']);
        $now = now()->toIso8601String();

        return ['operations' => $rows, 'cursor' => encrypt($now), 'server_time' => $now];
    }

    public function status(User $user, string $deviceId): array
    {
        $this->devices->owned($user, $deviceId);

        return ['open_conflicts' => DB::table('learner_sync_conflicts')->where('school_id', $user->school_id)->where('user_id', $user->id)->where('status', 'open')->count(), 'last_sync_at' => DB::table('learner_sync_operations')->where('user_id', $user->id)->max('created_at'), 'server_time' => now()->toIso8601String()];
    }

    public function conflicts(User $user)
    {
        $learner = $this->access->learner($user);

        return DB::table('learner_sync_conflicts')->where('school_id', $user->school_id)->where('user_id', $user->id)->where('learner_id', $learner->id)->where('status', 'open')->select('id', 'operation_uuid', 'entity_type', 'entity_id', 'client_version', 'server_version', 'safe_server_record', 'status', 'created_at')->paginate(20);
    }

    public function resolve(User $user, string $id): void
    {
        $learner = $this->access->learner($user);
        $updated = DB::table('learner_sync_conflicts')->whereKey($id)->where('school_id', $user->school_id)->where('user_id', $user->id)->where('learner_id', $learner->id)->where('status', 'open')->update(['status' => 'server_wins', 'resolved_at' => now(), 'updated_at' => now()]);
        abort_unless($updated, 404);
    }

    private function apply(User $user, string $deviceId, array $operation): array
    {
        $learner = $this->access->learner($user);
        $existing = DB::table('learner_sync_operations')->where('user_id', $user->id)->where('operation_uuid', $operation['operation_uuid'])->first();
        if ($existing) {
            return ['operation_uuid' => $existing->operation_uuid, 'status' => $existing->status, 'server_version' => $existing->server_version];
        }
        abort_unless(isset(self::ENTITIES[$operation['entity_type']]), 422, 'Unsupported learner sync entity.');
        abort_unless(($operation['operation'] ?? 'update') === 'update', 422, 'Offline deletion is not supported.');
        $spec = self::ENTITIES[$operation['entity_type']];
        if ($operation['entity_type'] === 'notification_state') {
            return $this->notification($user, $learner->id, $deviceId, $operation);
        }
        if ($operation['entity_type'] === 'announcement_read') {
            return $this->announcement($user, $learner->id, $deviceId, $operation);
        }
        if ($operation['entity_type'] === 'offline_resource') {
            return $this->offlineResource($user, $learner->id, $deviceId, $operation);
        }

        return DB::transaction(function () use ($user, $learner, $deviceId, $operation, $spec) {
            $query = DB::table($spec['table'])->whereKey($operation['entity_id'])->where('school_id', $user->school_id);
            $query->where('learner_id', $learner->id);
            $record = $query->lockForUpdate()->first();
            abort_unless($record, 404);
            if ($operation['entity_type'] === 'homework_submission_draft') {
                abort_unless($record->submission_status === 'draft', 409, 'Submitted homework cannot be changed offline.');
            }
            $version = (int) ($record->version ?? 1);
            if ((int) $operation['base_version'] !== $version) {
                return $this->conflict($user, $learner->id, $deviceId, $operation, $version, collect((array) $record)->only($spec['fields'])->all());
            }
            $payload = collect($operation['payload'] ?? [])->only($spec['fields'])->all();
            abort_if($payload === [], 422, 'No permitted learner sync fields supplied.');
            if (isset($payload['text_response'])) {
                $payload['text_response'] = strip_tags($payload['text_response']);
            }
            if (isset($payload['external_url'])) {
                $payload['external_url'] = $this->homework->safeUrl($payload['external_url']);
            }
            if (isset($payload['display_name'])) {
                $payload['display_name'] = strip_tags($payload['display_name']);
            }
            $updated = DB::table($spec['table'])->whereKey($record->id)->where('version', $version)->update($payload + ['version' => $version + 1, 'updated_at' => now()]);
            abort_unless($updated === 1, 409, 'The server record changed before the sync operation completed.');
            $this->log($user, $learner->id, $deviceId, $operation, 'accepted', $version + 1);

            return ['operation_uuid' => $operation['operation_uuid'], 'status' => 'accepted', 'server_version' => $version + 1];
        });
    }

    private function notification(User $user, string $learnerId, string $deviceId, array $operation): array
    {
        return DB::transaction(function () use ($user, $learnerId, $deviceId, $operation) {
            $record = DB::table('notifications')->whereKey($operation['entity_id'])->where('school_id', $user->school_id)->where('user_id', $user->id)->lockForUpdate()->first();
            abort_unless($record, 404);
            $version = (int) $record->version;
            if ((int) $operation['base_version'] !== $version) {
                return $this->conflict($user, $learnerId, $deviceId, $operation, $version, ['state' => $record->state, 'is_read' => (bool) $record->is_read]);
            }
            $state = $operation['payload']['state'] ?? null;
            abort_unless(in_array($state, ['read', 'unread', 'archived', 'dismissed'], true), 422, 'Unsupported notification state.');
            $values = ['state' => $state, 'is_read' => $state !== 'unread', 'read_at' => $state === 'read' ? now() : ($state === 'unread' ? null : $record->read_at), 'archived_at' => $state === 'archived' ? now() : $record->archived_at, 'dismissed_at' => $state === 'dismissed' ? now() : $record->dismissed_at, 'version' => $version + 1, 'updated_at' => now()];
            abort_unless(DB::table('notifications')->whereKey($record->id)->where('version', $version)->update($values) === 1, 409);
            $this->log($user, $learnerId, $deviceId, $operation, 'accepted', $version + 1);

            return ['operation_uuid' => $operation['operation_uuid'], 'status' => 'accepted', 'server_version' => $version + 1];
        });
    }

    private function announcement(User $user, string $learnerId, string $deviceId, array $operation): array
    {
        return DB::transaction(function () use ($user, $learnerId, $deviceId, $operation) {
            $visible = DB::table('communications')->join('communication_recipient_snapshots', 'communication_recipient_snapshots.communication_id', '=', 'communications.id')->where('communications.id', $operation['entity_id'])->where('communications.school_id', $user->school_id)->where('communications.communication_type', 'announcement')->where('communications.status', 'sent')->where('communication_recipient_snapshots.user_id', $user->id)->exists();
            abort_unless($visible, 404);
            $record = DB::table('announcement_reads')->where('communication_id', $operation['entity_id'])->where('user_id', $user->id)->lockForUpdate()->first();
            $version = (int) ($record->version ?? 1);
            if ((int) $operation['base_version'] !== $version) {
                return $this->conflict($user, $learnerId, $deviceId, $operation, $version, ['read' => (bool) $record]);
            }
            abort_unless(($operation['payload']['read'] ?? null) === true, 422, 'Announcements may only be marked read offline.');
            if ($record) {
                DB::table('announcement_reads')->whereKey($record->id)->where('version', $version)->update(['read_at' => now(), 'version' => $version + 1, 'updated_at' => now()]);
            } else {
                DB::table('announcement_reads')->insert(['id' => (string) Str::uuid(), 'school_id' => $user->school_id, 'communication_id' => $operation['entity_id'], 'user_id' => $user->id, 'read_at' => now(), 'version' => $version + 1, 'updated_at' => now()]);
            }
            $this->log($user, $learnerId, $deviceId, $operation, 'accepted', $version + 1);

            return ['operation_uuid' => $operation['operation_uuid'], 'status' => 'accepted', 'server_version' => $version + 1];
        });
    }

    private function offlineResource(User $user, string $learnerId, string $deviceId, array $operation): array
    {
        return DB::transaction(function () use ($user, $learnerId, $deviceId, $operation) {
            $this->offline->visible($user, $operation['entity_id']);
            $record = DB::table('learner_offline_resources')->where('school_id', $user->school_id)->where('learner_id', $learnerId)->where('resource_id', $operation['entity_id'])->lockForUpdate()->first();
            $version = (int) ($record->version ?? 1);
            if ((int) $operation['base_version'] !== $version) {
                return $this->conflict($user, $learnerId, $deviceId, $operation, $version, ['available' => $record && ! $record->revoked_at]);
            }
            $available = $operation['payload']['available'] ?? null;
            abort_unless(is_bool($available), 422, 'Offline availability must be boolean.');
            if ($available) {
                $offline = $this->offline->mark($user, $operation['entity_id']);
                DB::table('learner_offline_resources')->whereKey($offline->id)->update(['version' => $version + 1, 'updated_at' => now()]);
            } else {
                abort_unless($record, 404);
                DB::table('learner_offline_resources')->whereKey($record->id)->where('version', $version)->update(['revoked_at' => now(), 'version' => $version + 1, 'updated_at' => now()]);
            }
            $this->log($user, $learnerId, $deviceId, $operation, 'accepted', $version + 1);

            return ['operation_uuid' => $operation['operation_uuid'], 'status' => 'accepted', 'server_version' => $version + 1];
        });
    }

    private function conflict(User $user, string $learnerId, string $deviceId, array $operation, int $version, array $safe): array
    {
        $id = (string) Str::uuid();
        DB::table('learner_sync_conflicts')->insert(['id' => $id, 'school_id' => $user->school_id, 'user_id' => $user->id, 'learner_id' => $learnerId, 'device_id' => $deviceId, 'operation_uuid' => $operation['operation_uuid'], 'entity_type' => $operation['entity_type'], 'entity_id' => $operation['entity_id'], 'client_version' => $operation['base_version'], 'server_version' => $version, 'safe_server_record' => json_encode($safe), 'status' => 'open', 'created_at' => now(), 'updated_at' => now()]);
        $this->log($user, $learnerId, $deviceId, $operation, 'conflict', $version);

        return ['operation_uuid' => $operation['operation_uuid'], 'status' => 'conflict', 'conflict_id' => $id, 'server_version' => $version, 'server_state' => $safe];
    }

    private function log(User $user, string $learnerId, string $deviceId, array $operation, string $status, int $version): void
    {
        DB::table('learner_sync_operations')->insert(['id' => (string) Str::uuid(), 'school_id' => $user->school_id, 'user_id' => $user->id, 'learner_id' => $learnerId, 'device_id' => $deviceId, 'operation_uuid' => $operation['operation_uuid'], 'entity_type' => $operation['entity_type'], 'entity_id' => $operation['entity_id'], 'operation' => $operation['operation'] ?? 'update', 'base_version' => $operation['base_version'], 'status' => $status, 'server_version' => $version, 'created_at' => now()]);
    }
}
