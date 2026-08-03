<?php

namespace App\Services\ParentPortal;

use App\Models\ParentSyncConflict;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ParentSyncService
{
    private const ENTITIES = ['preference', 'notification_state', 'announcement_read', 'profile_draft', 'draft_message', 'appointment_draft', 'consent_draft_response', 'offline_document_marker'];

    private const DRAFT_FIELDS = [
        'profile_draft' => ['display_name', 'language', 'timezone'],
        'draft_message' => ['learner_id', 'conversation_type', 'subject', 'message'],
        'appointment_draft' => ['learner_id', 'category', 'preferred_from', 'preferred_to', 'reason'],
        'consent_draft_response' => ['learner_id', 'consent_id', 'response', 'reason'],
        'offline_document_marker' => ['document_id', 'available'],
    ];

    public function __construct(private ParentPortalAccessService $access, private ParentDeviceService $devices) {}

    public function push(User $user, string $deviceId, array $operations): array
    {
        abort_if(count($operations) > 40 || strlen(json_encode($operations, JSON_THROW_ON_ERROR)) > 65536, 413, 'Parent sync batch is too large.');
        $device = $this->devices->owned($user, $deviceId);

        return collect($operations)->map(fn ($operation) => $this->apply($user, $device->id, $operation))->all();
    }

    public function pull(User $user, string $deviceId, ?string $cursor): array
    {
        $this->devices->owned($user, $deviceId);
        $since = $cursor ? decrypt($cursor) : now()->subDays(7)->toIso8601String();
        $rows = DB::table('parent_sync_operations')->where('school_id', $user->school_id)->where('user_id', $user->id)->where('created_at', '>', $since)->orderBy('created_at')->limit(100)->get(['operation_uuid', 'entity_type', 'entity_id', 'status', 'server_version', 'created_at']);
        $now = now()->toIso8601String();

        return ['operations' => $rows, 'cursor' => encrypt($now), 'server_time' => $now];
    }

    public function status(User $user, string $deviceId): array
    {
        $this->devices->owned($user, $deviceId);

        return ['open_conflicts' => DB::table('parent_sync_conflicts')->where('school_id', $user->school_id)->where('user_id', $user->id)->where('status', 'open')->count(), 'last_sync_at' => DB::table('parent_sync_operations')->where('user_id', $user->id)->max('created_at'), 'server_time' => now()->toIso8601String()];
    }

    public function conflicts(User $user)
    {
        $this->access->parent($user);

        return ParentSyncConflict::withoutGlobalScopes()->where('school_id', $user->school_id)->where('user_id', $user->id)->where('status', 'open')->paginate(20);
    }

    public function resolve(User $user, string $id): void
    {
        $this->access->parent($user);
        abort_unless(DB::table('parent_sync_conflicts')->whereKey($id)->where('school_id', $user->school_id)->where('user_id', $user->id)->where('status', 'open')->update(['status' => 'server_wins', 'resolved_at' => now(), 'updated_at' => now()]), 404);
    }

    private function apply(User $user, string $deviceId, array $operation): array
    {
        abort_unless(in_array($operation['entity_type'] ?? null, self::ENTITIES, true), 422, 'Unsupported parent sync entity.');
        abort_unless(($operation['operation'] ?? 'update') === 'update', 422, 'Offline deletion is not supported.');
        $existing = DB::table('parent_sync_operations')->where('user_id', $user->id)->where('operation_uuid', $operation['operation_uuid'])->first();
        if ($existing) {
            return ['operation_uuid' => $existing->operation_uuid, 'status' => $existing->status, 'server_version' => $existing->server_version];
        }
        if (isset(self::DRAFT_FIELDS[$operation['entity_type']])) {
            return $this->draft($user, $deviceId, $operation);
        }
        [$table, $query, $fields] = match ($operation['entity_type']) {
            'preference' => ['communication_preferences', DB::table('communication_preferences')->whereKey($operation['entity_id'])->where('school_id', $user->school_id)->where('user_id', $user->id), ['email_enabled', 'in_app_enabled', 'digest_frequency', 'quiet_hours_start', 'quiet_hours_end', 'timezone', 'language']],
            'notification_state' => ['notifications', DB::table('notifications')->whereKey($operation['entity_id'])->where('school_id', $user->school_id)->where('user_id', $user->id), ['state']],
            'announcement_read' => ['announcement_reads', DB::table('announcement_reads')->whereKey($operation['entity_id'])->where('school_id', $user->school_id)->where('user_id', $user->id), ['read_at']],
        };
        $record = $query->lockForUpdate()->first();
        abort_unless($record, 404);
        $version = (int) ($record->version ?? 1);
        if ((int) $operation['base_version'] !== $version) {
            $id = (string) Str::uuid();
            DB::table('parent_sync_conflicts')->insert(['id' => $id, 'school_id' => $user->school_id, 'user_id' => $user->id, 'device_id' => $deviceId, 'operation_uuid' => $operation['operation_uuid'], 'entity_type' => $operation['entity_type'], 'entity_id' => $operation['entity_id'], 'client_version' => $operation['base_version'], 'server_version' => $version, 'safe_server_state' => json_encode(collect((array) $record)->only($fields)->all()), 'status' => 'open', 'created_at' => now(), 'updated_at' => now()]);
            $this->log($user, $deviceId, $operation, 'conflict', $version);

            return ['operation_uuid' => $operation['operation_uuid'], 'status' => 'conflict', 'conflict_id' => $id, 'server_version' => $version];
        }
        $payload = collect($operation['payload'] ?? [])->only($fields)->all();
        abort_if($payload === [], 422, 'No permitted fields supplied.');
        DB::table($table)->whereKey($record->id)->where('version', $version)->update($payload + ['version' => $version + 1, 'updated_at' => now()]);
        $this->log($user, $deviceId, $operation, 'accepted', $version + 1);

        return ['operation_uuid' => $operation['operation_uuid'], 'status' => 'accepted', 'server_version' => $version + 1];
    }

    private function draft(User $user, string $deviceId, array $operation): array
    {
        return DB::transaction(function () use ($user, $deviceId, $operation) {
            $fields = self::DRAFT_FIELDS[$operation['entity_type']];
            $payload = collect($operation['payload'] ?? [])->only($fields)->all();
            abort_if($payload === [], 422, 'No permitted draft fields supplied.');
            if (isset($payload['learner_id'])) {
                $this->access->requireLinkedLearner($user, $payload['learner_id']);
            }
            foreach (['display_name', 'subject', 'message', 'reason'] as $field) {
                if (isset($payload[$field])) {
                    $payload[$field] = strip_tags((string) $payload[$field]);
                }
            }
            $record = DB::table('parent_offline_drafts')->where('school_id', $user->school_id)->where('user_id', $user->id)
                ->where('entity_type', $operation['entity_type'])->where('entity_id', $operation['entity_id'])->lockForUpdate()->first();
            $version = (int) ($record->version ?? 1);
            if ((int) $operation['base_version'] !== $version) {
                $id = (string) Str::uuid();
                DB::table('parent_sync_conflicts')->insert(['id' => $id, 'school_id' => $user->school_id, 'user_id' => $user->id, 'device_id' => $deviceId, 'operation_uuid' => $operation['operation_uuid'], 'entity_type' => $operation['entity_type'], 'entity_id' => $operation['entity_id'], 'client_version' => $operation['base_version'], 'server_version' => $version, 'safe_server_state' => json_encode(['draft_exists' => (bool) $record]), 'status' => 'open', 'created_at' => now(), 'updated_at' => now()]);
                $this->log($user, $deviceId, $operation, 'conflict', $version);

                return ['operation_uuid' => $operation['operation_uuid'], 'status' => 'conflict', 'conflict_id' => $id, 'server_version' => $version];
            }
            if ($record) {
                DB::table('parent_offline_drafts')->where('id', $record->id)->where('version', $version)->update(['safe_data' => json_encode($payload), 'version' => $version + 1, 'updated_at' => now()]);
            } else {
                DB::table('parent_offline_drafts')->insert(['id' => (string) Str::uuid(), 'school_id' => $user->school_id, 'user_id' => $user->id, 'entity_type' => $operation['entity_type'], 'entity_id' => $operation['entity_id'], 'safe_data' => json_encode($payload), 'version' => $version + 1, 'created_at' => now(), 'updated_at' => now()]);
            }
            $this->log($user, $deviceId, $operation, 'accepted', $version + 1);

            return ['operation_uuid' => $operation['operation_uuid'], 'status' => 'accepted', 'server_version' => $version + 1];
        });
    }

    private function log(User $user, string $device, array $operation, string $status, int $version): void
    {
        DB::table('parent_sync_operations')->insert(['id' => (string) Str::uuid(), 'school_id' => $user->school_id, 'user_id' => $user->id, 'device_id' => $device, 'operation_uuid' => $operation['operation_uuid'], 'entity_type' => $operation['entity_type'], 'entity_id' => $operation['entity_id'], 'base_version' => $operation['base_version'], 'status' => $status, 'server_version' => $version, 'created_at' => now()]);
    }
}
