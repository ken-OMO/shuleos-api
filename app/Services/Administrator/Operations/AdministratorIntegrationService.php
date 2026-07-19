<?php

namespace App\Services\Administrator\Operations;

use App\Models\User;
use App\Services\Administrator\AdministratorAuditService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AdministratorIntegrationService
{
    public function __construct(private AdministratorOperationsAccessService $access, private AdministratorAuditService $audit) {}

    public function apiKeys(User $user, ?string $id = null): mixed
    {
        $scope = $this->access->school($user, 'manage_api_keys');
        $query = DB::table('administrator_api_keys')->where(function ($query) use ($scope) {
            $query->where('school_id', $scope['school_id']);
            if ($scope['platform']) {
                $query->orWhere(fn ($platform) => $platform->whereNull('school_id')->where('scope_type', 'platform'));
            }
        });
        $query->select($this->apiKeySafe());

        return $id ? (array) $query->where('id', $id)->firstOrFail() : $query->latest()->limit(100)->get();
    }

    public function createApiKey(User $user, array $data): array
    {
        $scope = $data['scope_type'] === 'platform' ? $this->access->platform($user, 'manage_api_keys') : $this->access->school($user, 'manage_api_keys');
        $scopes = $this->apiScopes($data['scopes'], $scope['operation_scope']);
        if (Carbon::parse($data['expires_at'])->gt(now()->addYears(2))) {
            throw ValidationException::withMessages(['expires_at' => 'API keys may be valid for at most two years.']);
        }
        $plain = 'shuleos_'.Str::random(48);
        $id = (string) Str::uuid();
        DB::table('administrator_api_keys')->insert(['id' => $id, 'school_id' => $scope['target_school_id'], 'scope_type' => $scope['operation_scope'], 'name' => strip_tags($data['name']), 'key_prefix' => substr($plain, 0, 15), 'key_hash' => hash('sha256', $plain), 'scopes' => json_encode($scopes), 'rate_limit' => min((int) ($data['rate_limit'] ?? 60), 1000), 'ip_restrictions' => json_encode($this->ips($data['ip_restrictions'] ?? [])), 'expires_at' => $data['expires_at'], 'created_by' => $user->id, 'created_at' => now(), 'updated_at' => now()]);
        $this->audit->record($user, 'administrator_api_key_created', 'administrator_api_keys', $id, [], ['name' => strip_tags($data['name']), 'scope_type' => $scope['operation_scope'], 'scopes' => $scopes]);

        return $this->apiKeys($user, $id) + ['plaintext_key' => $plain, 'shown_once' => true];
    }

    public function apiKeyAction(User $user, string $id, string $action): array
    {
        $key = (object) $this->apiKeys($user, $id);
        $key->scope_type === 'platform' ? $this->access->platform($user, 'manage_api_keys') : $this->access->school($user, 'manage_api_keys');
        if ($action === 'revoke') {
            DB::table('administrator_api_keys')->where('id', $id)->update(['revoked_at' => now(), 'updated_at' => now()]);
            $this->audit->record($user, 'administrator_api_key_revoked', 'administrator_api_keys', $id);

            return $this->apiKeys($user, $id);
        }
        $plain = 'shuleos_'.Str::random(48);
        DB::table('administrator_api_keys')->where('id', $id)->update(['key_prefix' => substr($plain, 0, 15), 'key_hash' => hash('sha256', $plain), 'revoked_at' => null, 'updated_at' => now()]);
        $this->audit->record($user, 'administrator_api_key_rotated', 'administrator_api_keys', $id);

        return $this->apiKeys($user, $id) + ['plaintext_key' => $plain, 'shown_once' => true, 'previous_key_revoked' => true];
    }

    public function webhooks(User $user, ?string $id = null): mixed
    {
        $scope = $this->access->school($user, 'manage_webhooks');
        $query = DB::table('administrator_webhooks')->where(function ($query) use ($scope) {
            $query->where('school_id', $scope['school_id']);
            if ($scope['platform']) {
                $query->orWhere(fn ($platform) => $platform->whereNull('school_id')->where('scope_type', 'platform'));
            }
        });
        $query->select($this->webhookSafe());

        return $id ? (array) $query->where('id', $id)->firstOrFail() : $query->latest()->limit(100)->get();
    }

    public function saveWebhook(User $user, array $data, ?string $id = null): array
    {
        $scope = $data['scope_type'] === 'platform' ? $this->access->platform($user, 'manage_webhooks') : $this->access->school($user, 'manage_webhooks');
        $this->endpoint($data['endpoint']);
        $events = array_values(array_unique($data['events']));
        if (! $events || array_diff($events, config('administrator_operations.webhook_events'))) {
            throw ValidationException::withMessages(['events' => 'One or more webhook events are unsupported.']);
        }
        $existing = $id ? (object) $this->webhooks($user, $id) : null;
        $secret = $data['secret'] ?? ($existing ? null : Str::random(48));
        $values = ['school_id' => $scope['target_school_id'], 'scope_type' => $scope['operation_scope'], 'name' => strip_tags($data['name']), 'endpoint' => $data['endpoint'], 'events' => json_encode($events), 'enabled' => (bool) ($data['enabled'] ?? true), 'retry_limit' => min((int) ($data['retry_limit'] ?? 3), 5), 'updated_by' => $user->id, 'updated_at' => now()];
        if ($secret) {
            $values += ['secret_hash' => hash('sha256', $secret), 'secret_encrypted' => Crypt::encryptString($secret)];
        }
        if ($existing) {
            DB::table('administrator_webhooks')->where('id', $id)->update($values);
        } else {
            $id = (string) Str::uuid();
            DB::table('administrator_webhooks')->insert($values + ['id' => $id, 'created_by' => $user->id, 'created_at' => now()]);
        }
        $this->audit->record($user, 'administrator_webhook_'.($existing ? 'updated' : 'created'), 'administrator_webhooks', $id, [], ['name' => strip_tags($data['name']), 'events' => $events, 'scope_type' => $scope['operation_scope']]);

        return $this->webhooks($user, $id) + ($existing ? [] : ['signing_secret' => $secret, 'shown_once' => true]);
    }

    public function webhookAction(User $user, string $id, string $action): array
    {
        $webhook = (object) $this->webhooks($user, $id);
        $webhook->scope_type === 'platform' ? $this->access->platform($user, 'manage_webhooks') : $this->access->school($user, 'manage_webhooks');
        if ($action === 'disable') {
            DB::table('administrator_webhooks')->where('id', $id)->update(['enabled' => false, 'updated_by' => $user->id, 'updated_at' => now()]);

            return $this->webhooks($user, $id);
        }
        $secret = Str::random(48);
        DB::table('administrator_webhooks')->where('id', $id)->update(['secret_hash' => hash('sha256', $secret), 'secret_encrypted' => Crypt::encryptString($secret), 'rotated_at' => now(), 'updated_by' => $user->id, 'updated_at' => now()]);
        $this->audit->record($user, 'administrator_webhook_secret_rotated', 'administrator_webhooks', $id);

        return $this->webhooks($user, $id) + ['signing_secret' => $secret, 'shown_once' => true];
    }

    public function deliveries(User $user, string $id): mixed
    {
        $this->webhooks($user, $id);

        return DB::table('administrator_webhook_deliveries')->where('webhook_id', $id)->select('id', 'event', 'status', 'attempt_count', 'safe_failure_code', 'next_attempt_at', 'delivered_at', 'created_at', 'updated_at')->latest()->limit(100)->get();
    }

    public function retryWebhooks(int $limit = 50): int
    {
        return DB::table('administrator_webhook_deliveries')->where('status', 'failed')->where('attempt_count', '<', 3)->where(fn ($q) => $q->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now()))->limit(min($limit, 100))->update(['status' => 'queued', 'next_attempt_at' => null, 'updated_at' => now()]);
    }

    public function expireApiKeys(int $limit = 100): int
    {
        return DB::table('administrator_api_keys')->whereNull('revoked_at')->where('expires_at', '<=', now())->limit(min($limit, 500))->update(['revoked_at' => now(), 'updated_at' => now()]);
    }

    private function apiScopes(array $scopes, string $scopeType): array
    {
        $scopes = array_values(array_unique($scopes));
        if (! $scopes || array_diff($scopes, config('administrator_operations.api_key_scopes')) || ($scopeType === 'school' && in_array('platform.read', $scopes, true))) {
            throw ValidationException::withMessages(['scopes' => 'API key scopes are unsupported for this authority.']);
        }

return $scopes;
    }

    private function ips(array $ips): array
    {
        foreach ($ips as $ip) {
            if (! filter_var($ip, FILTER_VALIDATE_IP)) {
                throw ValidationException::withMessages(['ip_restrictions' => 'Every IP restriction must be a valid IP address.']);
            }
        }

return array_values(array_unique($ips));
    }

    private function endpoint(string $url): void
    {
        $parts = parse_url($url);
        $host = strtolower($parts['host'] ?? '');
        $local = app()->environment(['local', 'testing']) && in_array($host, ['localhost', '127.0.0.1', '::1'], true);
        if (($parts['scheme'] ?? '') !== 'https' && ! $local) {
            throw ValidationException::withMessages(['endpoint' => 'Webhook endpoints must use HTTPS.']);
        } if (! $local && ($host === '' || str_ends_with($host, '.local') || filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false && filter_var($host, FILTER_VALIDATE_IP))) {
            throw ValidationException::withMessages(['endpoint' => 'Private or reserved webhook targets are prohibited.']);
        }
    }

    private function apiKeySafe(): array
    {
        return ['id', 'school_id', 'scope_type', 'name', 'key_prefix', 'scopes', 'rate_limit', 'ip_restrictions', 'expires_at', 'last_used_at', 'revoked_at', 'created_at', 'updated_at'];
    }

    private function webhookSafe(): array
    {
        return ['id', 'school_id', 'scope_type', 'name', 'endpoint', 'events', 'enabled', 'retry_limit', 'rotated_at', 'created_at', 'updated_at'];
    }
}
