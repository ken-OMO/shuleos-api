<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\BaseApiController;
use App\Services\Administrator\Operations\AdministratorCacheService;
use App\Services\Administrator\Operations\AdministratorDiagnosticsService;
use App\Services\Administrator\Operations\AdministratorFeatureFlagService;
use App\Services\Administrator\Operations\AdministratorIntegrationService;
use App\Services\Administrator\Operations\AdministratorLogService;
use App\Services\Administrator\Operations\AdministratorMaintenanceService;
use App\Services\Administrator\Operations\AdministratorProviderConfigurationService;
use App\Services\Administrator\Operations\AdministratorQueueService;
use App\Services\Administrator\Operations\AdministratorRecoveryService;
use App\Services\Administrator\Operations\AdministratorSchedulerService;
use App\Services\Administrator\Operations\AdministratorStorageService;
use Illuminate\Http\Request;

class AdministratorOperationsController extends BaseApiController
{
    public function __construct(private AdministratorFeatureFlagService $flags, private AdministratorMaintenanceService $maintenance, private AdministratorProviderConfigurationService $providers, private AdministratorQueueService $queue, private AdministratorSchedulerService $scheduler, private AdministratorCacheService $cache, private AdministratorLogService $logs, private AdministratorStorageService $storage, private AdministratorRecoveryService $recovery, private AdministratorIntegrationService $integrations, private AdministratorDiagnosticsService $diagnostics) {}

    private function user()
    {
        return auth()->user();
    }

    public function flags(?string $flag = null)
    {
        return $this->success($flag ? $this->flags->show($this->user(), $flag) : $this->flags->index($this->user()));
    }

    public function saveFlag(Request $request, ?string $flag = null)
    {
        return $this->success($this->flags->save($this->user(), $request->validate(['key' => 'required|string|max:100', 'scope_type' => 'required|in:school,platform', 'enabled' => 'sometimes|boolean', 'rollout_percentage' => 'sometimes|nullable|integer|min:0|max:100', 'starts_at' => 'sometimes|nullable|date', 'ends_at' => 'sometimes|nullable|date|after:starts_at', 'metadata' => 'sometimes|array|max:5']), $flag));
    }

    public function flagAction(string $flag, string $action)
    {
        return $this->success($this->flags->action($this->user(), $flag, $action));
    }

    public function maintenance()
    {
        return $this->success($this->maintenance->index($this->user()));
    }

    public function maintenancePreview(Request $request)
    {
        return $this->success($this->maintenance->preview($this->user(), $request->validate($this->maintenanceRules(false))));
    }

    public function maintenanceSchedule(Request $request)
    {
        return $this->success($this->maintenance->schedule($this->user(), $request->validate($this->maintenanceRules(true))));
    }

    public function maintenanceAction(Request $request, string $action)
    {
        return $this->success($this->maintenance->action($this->user(), $request->validate(['maintenance_id' => 'required|uuid', 'confirmation' => 'sometimes|nullable|string', 'reason' => 'sometimes|nullable|string|max:1000']), $action));
    }

    public function providers(?string $category = null)
    {
        return $this->success($this->providers->index($this->user(), $category));
    }

    public function saveProvider(Request $request, string $category)
    {
        return $this->success($this->providers->save($this->user(), $category, $request->validate($this->providerRules())));
    }

    public function providerAction(Request $request, string $category, string $action)
    {
        return $this->success($action === 'disable' ? $this->providers->disable($this->user(), $category) : $this->providers->save($this->user(), $category, $request->validate($this->providerRules()), true));
    }

    public function providerHealth(string $category)
    {
        return $this->success($this->providers->health($this->user(), $category));
    }

    public function queue(string $view = 'summary', ?string $job = null)
    {
        return $this->success(match ($view) {
            'jobs' => $this->queue->jobs($this->user()), 'failed' => $this->queue->failed($this->user(), $job), default => $this->queue->summary($this->user())
        });
    }

    public function queueAction(Request $request, string $job, string $action)
    {
        return $this->success($action === 'retry' ? $this->queue->retry($this->user(), $job) : $this->queue->forget($this->user(), $job, $request->validate(['confirmation' => 'required|string'])['confirmation']));
    }

    public function scheduler(string $view = 'summary', ?string $task = null)
    {
        return $this->success($view === 'summary' ? $this->scheduler->summary($this->user()) : ($task ? $this->scheduler->task($this->user(), $task) : $this->scheduler->tasks($this->user())));
    }

    public function runTask(string $task)
    {
        return $this->success($this->scheduler->run($this->user(), $task));
    }

    public function cache()
    {
        return $this->success($this->cache->summary($this->user()));
    }

    public function cachePreview(Request $request)
    {
        return $this->success($this->cache->preview($this->user(), $request->validate(['groups' => 'required|array|min:1|max:6', 'groups.*' => 'string'])));
    }

    public function cacheClear(Request $request)
    {
        return $this->success($this->cache->clear($this->user(), $request->validate(['preview_id' => 'required|uuid', 'groups' => 'required|array|min:1|max:6', 'groups.*' => 'string'])));
    }

    public function logs(Request $request, ?string $log = null, bool $entries = false)
    {
        return $this->success($entries ? $this->logs->entries($this->user(), $log, $request->integer('limit', 100)) : ($log ? $this->logs->show($this->user(), $log) : $this->logs->index($this->user())));
    }

    public function storage(string $view = 'summary')
    {
        return $this->success($this->storage->summary($this->user(), $view));
    }

    public function storageAction(Request $request, string $file, string $action)
    {
        return $this->success($this->storage->action($this->user(), $file, $action, $request->validate(['reason' => 'sometimes|nullable|string|max:1000'])['reason'] ?? null));
    }

    public function backups(?string $backup = null)
    {
        return $this->success($this->recovery->backups($this->user(), $backup));
    }

    public function backupPreview(Request $request)
    {
        return $this->success($this->recovery->backupPreview($this->user(), $request->validate(['scope_type' => 'required|in:school,platform', 'backup_type' => 'required|string'])));
    }

    public function createBackup(Request $request)
    {
        return $this->success($this->recovery->createBackup($this->user(), $request->validate(['preview_id' => 'required|uuid', 'scope_type' => 'required|in:school,platform', 'backup_type' => 'required|string'])));
    }

    public function backupAction(string $backup, string $action)
    {
        return $this->success($action === 'verify' ? $this->recovery->verify($this->user(), $backup) : $this->recovery->archive($this->user(), $backup));
    }

    public function restores(?string $restore = null)
    {
        return $this->success($this->recovery->restores($this->user(), $restore));
    }

    public function restorePreview(Request $request)
    {
        return $this->success($this->recovery->restorePreview($this->user(), $request->validate(['backup_id' => 'required|uuid', 'dry_run' => 'required|boolean'])));
    }

    public function createRestore(Request $request)
    {
        return $this->success($this->recovery->createRestore($this->user(), $request->validate(['preview_id' => 'required|uuid', 'backup_id' => 'required|uuid', 'pre_restore_backup_id' => 'required|uuid|different:backup_id', 'dry_run' => 'required|boolean', 'reason' => 'required|string|max:1000', 'confirmation' => 'required|string'])));
    }

    public function cancelRestore(string $restore)
    {
        return $this->success($this->recovery->cancelRestore($this->user(), $restore));
    }

    public function apiKeys(?string $key = null)
    {
        return $this->success($this->integrations->apiKeys($this->user(), $key));
    }

    public function createApiKey(Request $request)
    {
        return $this->success($this->integrations->createApiKey($this->user(), $request->validate(['scope_type' => 'required|in:school,platform', 'name' => 'required|string|max:120', 'scopes' => 'required|array|min:1|max:10', 'scopes.*' => 'string', 'rate_limit' => 'sometimes|integer|min:1|max:1000', 'ip_restrictions' => 'sometimes|array|max:20', 'ip_restrictions.*' => 'string', 'expires_at' => 'required|date|after:now'])));
    }

    public function apiKeyAction(string $key, string $action)
    {
        return $this->success($this->integrations->apiKeyAction($this->user(), $key, $action));
    }

    public function webhooks(?string $webhook = null)
    {
        return $this->success($this->integrations->webhooks($this->user(), $webhook));
    }

    public function saveWebhook(Request $request, ?string $webhook = null)
    {
        return $this->success($this->integrations->saveWebhook($this->user(), $request->validate(['scope_type' => 'required|in:school,platform', 'name' => 'required|string|max:120', 'endpoint' => 'required|url|max:1000', 'events' => 'required|array|min:1|max:20', 'events.*' => 'string', 'secret' => 'sometimes|string|min:32|max:200', 'enabled' => 'sometimes|boolean', 'retry_limit' => 'sometimes|integer|min:0|max:5']), $webhook));
    }

    public function webhookAction(string $webhook, string $action)
    {
        return $this->success($this->integrations->webhookAction($this->user(), $webhook, $action));
    }

    public function webhookDeliveries(string $webhook)
    {
        return $this->success($this->integrations->deliveries($this->user(), $webhook));
    }

    public function diagnostics(?string $run = null)
    {
        return $this->success($this->diagnostics->index($this->user(), $run));
    }

    public function runDiagnostics(Request $request)
    {
        return $this->success($this->diagnostics->run($this->user(), $request->validate(['checks' => 'required|array|min:1|max:20', 'checks.*' => 'string'])['checks']));
    }

    public function notices(Request $request, ?string $notice = null, ?string $action = null)
    {
        $data = $request->isMethod('get') || $action ? null : $request->validate(['notice_type' => 'required|in:maintenance,degradation,release,planned_downtime,security,administrator', 'audience' => 'required|in:platform,school_administrators,all_administrators', 'title' => 'required|string|max:255', 'message' => 'required|string|max:5000', 'starts_at' => 'sometimes|nullable|date', 'ends_at' => 'sometimes|nullable|date|after:starts_at']);

        return $this->success($this->diagnostics->notices($this->user(), $data, $notice, $action));
    }

    public function releases(Request $request)
    {
        return $this->success($this->diagnostics->releases($this->user(), $request->routeIs('admin.operations.releases.current')));
    }

    public function settings(Request $request)
    {
        return $this->success($this->diagnostics->settings($this->user(), $request->isMethod('put') ? $request->validate(['settings' => 'required|array|max:20'])['settings'] : null));
    }

    public function disasterRecovery()
    {
        return $this->success($this->diagnostics->disasterRecovery($this->user()));
    }

    private function maintenanceRules(bool $preview): array
    {
        return ['scope_type' => 'required|in:school,platform', 'reason' => 'required|string|max:1000', 'starts_at' => 'required|date|after_or_equal:now', 'ends_at' => 'required|date|after:starts_at'] + ($preview ? ['preview_id' => 'required|uuid'] : []);
    }

    private function providerRules(): array
    {
        return ['provider' => 'required|string|max:60', 'enabled' => 'sometimes|boolean', 'secrets' => 'sometimes|array|max:10', 'secrets.*' => 'nullable|string|max:2000'];
    }
}
