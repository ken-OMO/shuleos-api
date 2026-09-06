<?php

namespace App\Services\Communication;

use App\Models\User;
use App\Services\Auth\AuthContextService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class EmergencyCommunicationService
{
    public function __construct(
        private CommunicationService $communications,
        private AuthContextService $authContext
    ) {}

    public function preview(User $user, array $data): array
    {
        abort_unless(config('communication.emergency_enabled'), 503, 'Emergency communication is disabled by platform policy.');
        abort_unless($this->allowed($user), 403);
        $data = $this->definition($data);
        $preview = $this->communications->previewDefinition($user, $data);
        $token = Str::random(64);
        DB::table('communication_emergency_confirmations')->insert(['id' => (string) Str::uuid(), 'school_id' => $user->school_id, 'user_id' => $user->id, 'token_hash' => hash('sha256', $token), 'payload_hash' => hash('sha256', json_encode($data)), 'expires_at' => now()->addMinutes(10), 'created_at' => now()]);

        return $preview + ['confirmation_token' => $token, 'confirmation_expires_at' => now()->addMinutes(10)];
    }

    public function send(User $user, array $data, string $token): object
    {
        abort_unless($this->allowed($user), 403);
        $definition = $this->definition($data);

        return DB::transaction(function () use ($user, $definition, $token) {
            $confirmation = DB::table('communication_emergency_confirmations')->where('school_id', $user->school_id)->where('user_id', $user->id)->where('token_hash', hash('sha256', $token))->where('payload_hash', hash('sha256', json_encode($definition)))->whereNull('used_at')->where('expires_at', '>', now())->lockForUpdate()->first();
            abort_unless($confirmation, 422, 'Emergency confirmation is invalid or expired.');
            DB::table('communication_emergency_confirmations')->where('id', $confirmation->id)->update(['used_at' => now()]);
            $communication = $this->communications->create($user, $definition);
            DB::table('communication_audit_logs')->insert(['id' => (string) Str::uuid(), 'school_id' => $user->school_id, 'communication_id' => $communication->id, 'actor_user_id' => $user->id, 'action' => 'emergency_confirmed', 'entity_type' => 'communication', 'entity_id' => $communication->id, 'metadata' => json_encode(['emergency_category' => $definition['metadata']['emergency_category'], 'reason' => $definition['metadata']['reason']]), 'created_at' => now()]);

            return $this->communications->submit($user, $communication->id);
        });
    }

    private function definition(array $data): array
    {
        abort_if(blank($data['reason'] ?? null) || blank($data['emergency_category'] ?? null), 422, 'Emergency reason and category are required.');
        $channels = ['in_app', 'email'];
        if (($data['attempt_sms'] ?? false) === true) {
            $channels[] = 'sms';
        }

        return ['communication_type' => 'emergency', 'category' => 'emergency', 'priority' => 'critical', 'subject' => $data['subject'], 'body' => $data['body'], 'channels' => $channels, 'targets' => $data['targets'], 'metadata' => ['emergency_category' => $data['emergency_category'], 'reason' => $data['reason']]];
    }

    private function allowed(User $user): bool
    {
        return $this->authContext->hasPermission(
            $user,
            'send_emergency_broadcasts'
        );
    }
}
