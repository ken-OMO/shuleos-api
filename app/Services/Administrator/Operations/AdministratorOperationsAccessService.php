<?php

namespace App\Services\Administrator\Operations;

use App\Models\User;
use App\Services\Administrator\AdministratorPortalAccessService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AdministratorOperationsAccessService
{
    public function __construct(private AdministratorPortalAccessService $administrators) {}

    public function school(User $user, string $permission): array
    {
        $scope = $this->administrators->require($user, 'access_administrator_operations');
        if (! $this->administrators->has($user, $permission)) {
            throw new AuthorizationException('Operational permission denied.');
        }

        return $scope + ['target_school_id' => $scope['school_id'], 'operation_scope' => 'school'];
    }

    public function platform(User $user, string $permission): array
    {
        $this->administrators->require($user, 'access_administrator_operations');

        return $this->administrators->requirePlatform($user, $permission) + ['target_school_id' => null, 'operation_scope' => 'platform'];
    }

    public function scope(User $user, string $scope, string $schoolPermission, string $platformPermission): array
    {
        return $scope === 'platform' ? $this->platform($user, $platformPermission) : $this->school($user, $schoolPermission);
    }

    public function confirm(User $user, ?string $phrase, string $expected): void
    {
        if (! hash_equals($expected, (string) $phrase)) {
            throw new AuthorizationException('Exact confirmation phrase required.');
        }
        if (! $user->last_login || $user->last_login->lt(now()->subMinutes((int) config('administrator_operations.recent_auth_minutes', 30)))) {
            throw new AuthorizationException('Recent authentication is required.');
        }
    }

    public function preview(User $user, string $operation, array $request, array $safeSummary, string $scope, ?string $schoolId): array
    {
        $id = (string) Str::uuid();
        DB::table('administrator_operation_previews')->insert(['id' => $id, 'school_id' => $schoolId, 'user_id' => $user->id, 'operation' => $operation, 'scope_type' => $scope, 'request_hash' => $this->hash($request), 'safe_summary' => json_encode($safeSummary), 'expires_at' => now()->addMinutes(10), 'created_at' => now()]);

        return ['preview_id' => $id, 'operation' => $operation, 'summary' => $safeSummary, 'expires_at' => now()->addMinutes(10)->toIso8601String()];
    }

    public function consumePreview(User $user, string $id, string $operation, array $request): void
    {
        $preview = DB::table('administrator_operation_previews')->where('id', $id)->where('user_id', $user->id)->where('operation', $operation)->whereNull('consumed_at')->where('expires_at', '>', now())->lockForUpdate()->first();
        if (! $preview || ! hash_equals($preview->request_hash, $this->hash($request))) {
            throw new AuthorizationException('A matching unexpired preview is required.');
        }
        DB::table('administrator_operation_previews')->where('id', $id)->update(['consumed_at' => now()]);
    }

    private function hash(array $request): string
    {
        ksort($request);

        return hash('sha256', json_encode($request, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}
