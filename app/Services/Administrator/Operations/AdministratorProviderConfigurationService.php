<?php

namespace App\Services\Administrator\Operations;

use App\Models\User;
use App\Services\Administrator\AdministratorAuditService;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AdministratorProviderConfigurationService
{
    public function __construct(private AdministratorOperationsAccessService $access, private AdministratorAuditService $audit) {}

    public function index(User $user, ?string $category = null): mixed
    {
        $this->access->platform($user, 'view_provider_configuration');
        $query = DB::table('administrator_provider_configurations')->select($this->safe())->orderBy('category');

        return $category ? (array) $query->where('category', $category)->firstOrFail() : $query->get();
    }

    public function save(User $user, string $category, array $data, bool $rotate = false): array
    {
        $this->access->platform($user, $rotate ? 'rotate_provider_secrets' : 'manage_provider_configuration');
        $this->validate($category, $data['provider']);
        $existing = DB::table('administrator_provider_configurations')->where('scope_type', 'platform')->where('category', $category)->first();
        $secrets = collect($data['secrets'] ?? [])->only(['api_key', 'secret', 'token', 'username', 'password', 'passkey', 'project_id'])->filter(fn ($value) => filled($value))->all();
        if ($rotate && ! $secrets) {
            throw ValidationException::withMessages(['secrets' => 'New write-only credentials are required for rotation.']);
        }
        $values = ['scope_type' => 'platform', 'school_id' => null, 'category' => $category, 'provider' => $data['provider'], 'enabled' => (bool) ($data['enabled'] ?? true), 'secret_present' => $secrets ? true : (bool) ($existing->secret_present ?? false), 'updated_by' => $user->id, 'updated_at' => now(), 'version' => ($existing->version ?? 0) + 1];
        if ($secrets) {
            $values['configuration_encrypted'] = Crypt::encryptString(json_encode($secrets));
        }
        if ($rotate) {
            $values['rotated_at'] = now();
        }
        if ($existing) {
            DB::table('administrator_provider_configurations')->where('id', $existing->id)->update($values);
            $id = $existing->id;
        } else {
            $id = (string) Str::uuid();
            DB::table('administrator_provider_configurations')->insert($values + ['id' => $id, 'created_at' => now()]);
        }
        DB::table('administrator_provider_configuration_history')->insert(['id' => (string) Str::uuid(), 'configuration_id' => $id, 'actor_user_id' => $user->id, 'action' => $rotate ? 'rotated' : 'updated', 'provider' => $data['provider'], 'secret_present' => (bool) $values['secret_present'], 'created_at' => now()]);
        $this->audit->record($user, 'administrator_provider_'.($rotate ? 'rotated' : 'updated'), 'administrator_provider_configurations', $id, [], ['category' => $category, 'provider' => $data['provider'], 'secret_present' => (bool) $values['secret_present']]);

        return $this->index($user, $category);
    }

    public function disable(User $user, string $category): array
    {
        $this->access->platform($user, 'manage_provider_configuration');
        $row = DB::table('administrator_provider_configurations')->where('category', $category)->firstOrFail();
        DB::table('administrator_provider_configurations')->where('id', $row->id)->update(['enabled' => false, 'updated_by' => $user->id, 'updated_at' => now()]);
        $this->audit->record($user, 'administrator_provider_disabled', 'administrator_provider_configurations', $row->id, [], ['category' => $category]);

        return $this->index($user, $category);
    }

    public function health(User $user, string $category): array
    {
        $row = $this->index($user, $category);

        return ['category' => $category, 'provider' => $row['provider'], 'enabled' => (bool) $row['enabled'], 'configured' => (bool) $row['secret_present'], 'status' => $row['enabled'] && $row['secret_present'] ? 'ready' : 'not_ready', 'check_type' => 'passive_configuration_only'];
    }

    private function validate(string $category, string $provider): void
    {
        $allowed = config('administrator_operations.provider_names.'.$category);
        if (! in_array($category, config('administrator_operations.provider_categories', []), true) || ! $allowed || ! in_array($provider, $allowed, true)) {
            throw ValidationException::withMessages(['provider' => 'Unsupported provider configuration.']);
        }
    }

    private function safe(): array
    {
        return ['id', 'scope_type', 'school_id', 'category', 'provider', 'secret_present', 'enabled', 'version', 'rotated_at', 'created_at', 'updated_at'];
    }
}
