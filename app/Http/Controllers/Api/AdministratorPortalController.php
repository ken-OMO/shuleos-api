<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\BaseApiController;
use App\Services\Administrator\AdministratorImportService;
use App\Services\Administrator\AdministratorOperationsService;
use App\Services\Administrator\AdministratorPortalAccessService;
use App\Services\Administrator\AdministratorPortalService;
use App\Services\Administrator\AdministratorRolePermissionService;
use App\Services\Administrator\AdministratorUserService;
use App\Services\Administrator\SchoolBrandingAdministrationService;
use App\Services\Administrator\SchoolLifecycleAdministrationService;
use App\Services\Platform\PlatformSchoolOnboardingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdministratorPortalController extends BaseApiController
{
    public function __construct(
        private AdministratorPortalAccessService $access,
        private AdministratorPortalService $portal,
        private AdministratorUserService $users,
        private AdministratorRolePermissionService $roles,
        private SchoolLifecycleAdministrationService $lifecycle,
        private SchoolBrandingAdministrationService $branding,
        private AdministratorImportService $imports,
        private AdministratorOperationsService $operations,
    ) {}

    private function user()
    {
        return auth()->user();
    }

    public function dashboard(Request $request)
    {
        return $this->success($this->portal->dashboard($this->user(), $request->routeIs('admin.dashboard.platform')));
    }

    public function school()
    {
        return $this->success($this->portal->safeSchool($this->access->school($this->user())));
    }

    public function updateSchool(Request $request)
    {
        return $this->success($this->portal->safeSchool($this->portal->updateSchool($this->user(), $request->validate($this->schoolRules()))));
    }

    public function completeness()
    {
        return $this->success($this->portal->completeness($this->user()));
    }

    public function platformSchools(Request $request)
    {
        $this->access->requirePlatform($this->user(), 'manage_school_lifecycle');

        return $this->success(DB::table('schools')->where('is_deleted', false)->when($request->string('state')->toString(), fn ($q, $state) => $q->where('lifecycle_state', $state))->select('id', 'school_name', 'school_code', 'short_name', 'lifecycle_state', 'active', 'created_at', 'updated_at')->orderBy('school_name')->paginate(min($request->integer('per_page', 25), 100)));
    }

    public function platformSchool(string $school)
    {
        return $this->success($this->portal->safeSchool($this->access->platformSchool($this->user(), $school)));
    }

    public function lifecycle(Request $request, string $school, string $action)
    {
        $state = match ($action) {
            'activate', 'resume' => 'active', 'suspend' => 'suspended', 'enter-read-only' => 'read_only', 'lock' => 'locked', 'archive' => 'archived', default => abort(404)
        };
        $data = $request->validate(['reason' => 'nullable|string|max:1000']);

        return $this->success($this->portal->safeSchool($this->lifecycle->transition($this->user(), $school, $state, $data['reason'] ?? null)));
    }

    public function users(Request $request)
    {
        return $this->success($this->users->index($this->user(), $request->only(['role', 'status', 'search', 'per_page'])));
    }

    public function userShow(string $user)
    {
        return $this->success($this->users->find($this->user(), $user));
    }

    public function userCreate(Request $request)
    {
        return $this->created($this->users->create($this->user(), $request->validate($this->userRules())));
    }

    public function userUpdate(Request $request, string $user)
    {
        return $this->success($this->users->update($this->user(), $user, $request->validate($this->userRules(true))));
    }

    public function userAction(string $user, string $action)
    {
        return $this->success($this->users->action($this->user(), $user, $action));
    }

    public function roles()
    {
        return $this->success($this->roles->roles($this->user()));
    }

    public function role(string $role)
    {
        return $this->success($this->roles->role($this->user(), $role));
    }

    public function createRole(Request $request)
    {
        return $this->created($this->roles->create($this->user(), $request->validate(['name' => 'required|string|max:120'])['name']));
    }

    public function updateRole(Request $request, string $role)
    {
        return $this->success($this->roles->update($this->user(), $role, $request->validate(['name' => 'required|string|max:120'])['name']));
    }

    public function rolePermissions(Request $request, string $role)
    {
        return $this->success($this->roles->assign($this->user(), $role, $request->validate(['permissions' => 'required|array|max:300', 'permissions.*' => 'required|string|max:120'])['permissions']));
    }

    public function permissions(Request $request)
    {
        $items = $this->roles->permissions($this->user());

        return $this->success($request->routeIs('admin.permissions.modules') ? $items->groupBy('module_name') : $items);
    }

    public function academic(string $type)
    {
        return $this->success($type === 'status' ? $this->portal->academicSetup($this->user()) : $this->portal->academic($this->user(), str_replace('-', '_', $type)));
    }

    public function branding()
    {
        return $this->success($this->branding->index($this->user()));
    }

    public function updateBranding(Request $request)
    {
        return $this->success($this->branding->settings($this->user(), $request->validate(['primary_color' => 'sometimes|string', 'secondary_color' => 'sometimes|string', 'report_card_footer' => 'sometimes|nullable|string|max:1000', 'letterhead_text' => 'sometimes|nullable|string|max:1000'])));
    }

    public function uploadBranding(Request $request)
    {
        $data = $request->validate(['file' => 'required|file|max:4096', 'asset_type' => 'required|string']);

        return $this->created($this->branding->upload($this->user(), $request->file('file'), $data['asset_type']));
    }

    public function archiveBranding(string $asset)
    {
        return $this->success($this->branding->archive($this->user(), $asset));
    }

    public function subscription(Request $request, ?string $school = null)
    {
        if ($request->routeIs('admin.subscription.history')) {
            return $this->success($this->portal->subscriptionHistory($this->user()));
        }
        if ($request->routeIs('admin.subscription.entitlements')) {
            return $this->success($this->portal->subscription($this->user())['enabled_modules']);
        }
        if ($request->routeIs('admin.platform.subscriptions')) {
            $this->access->requirePlatform($this->user(), 'view_platform_subscriptions');

            return $this->success(DB::table('school_subscriptions')->join('schools', 'schools.id', '=', 'school_subscriptions.school_id')->leftJoin('subscription_packages', 'subscription_packages.id', '=', 'school_subscriptions.package_id')->select('schools.id as school_id', 'schools.school_name', 'schools.lifecycle_state', 'subscription_packages.package_name as plan', 'school_subscriptions.status', 'school_subscriptions.expiry_date', 'school_subscriptions.grace_end_date')->paginate(50));
        }

        return $this->success($this->portal->subscription($this->user(), $school));
    }

    public function modules(?string $module = null)
    {
        return $this->success($this->portal->modules($this->user(), $module ? str_replace('-', '_', $module) : null));
    }

    public function audit(Request $request, ?string $event = null)
    {
        return $this->success($request->routeIs('admin.audit.summary') ? $this->operations->auditSummary($this->user()) : $this->operations->audit($this->user(), $request->only(['module', 'action', 'actor', 'date_from', 'date_to', 'per_page']), $event));
    }

    public function security(string $view = 'summary')
    {
        return $this->success($this->operations->security($this->user(), $view));
    }

    public function securityUserAction(string $user, string $action)
    {
        return $this->success($this->users->action($this->user(), $user, $action));
    }

    public function devices(Request $request, ?string $device = null)
    {
        return $this->success($this->operations->devices($this->user(), $request->only(['role']), $device));
    }

    public function revokeDevice(string $device)
    {
        return $this->success($this->operations->revokeDevice($this->user(), $device));
    }

    public function communications(Request $request, string $view = 'summary')
    {
        $data = $view === 'policies-update' ? $request->validate(['category' => 'required|string|max:60', 'enabled_channels' => 'sometimes|array', 'enabled_channels.*' => 'string|in:in_app,email,sms', 'minimum_priority' => 'sometimes|in:low,normal,high,critical', 'requires_approval' => 'sometimes|boolean', 'allow_scheduling' => 'sometimes|boolean', 'sms_enabled' => 'sometimes|boolean']) : [];

        return $this->success($this->operations->communications($this->user(), $view, $data));
    }

    public function payments(string $view)
    {
        return $this->success($this->operations->payments($this->user(), $view));
    }

    public function imports()
    {
        return $this->success($this->imports->index($this->user()));
    }

    public function previewImport(Request $request)
    {
        $data = $request->validate(['file' => 'required|file|max:5120', 'import_type' => 'required|string', 'idempotency_key' => 'required|string|min:8|max:200']);

        return $this->success($this->imports->preview($this->user(), $request->file('file'), $data['import_type'], $data['idempotency_key']));
    }

    public function queueImport(Request $request)
    {
        return $this->success($this->imports->queue($this->user(), $request->validate(['preview_id' => 'required|uuid'])['preview_id']));
    }

    public function import(string $import)
    {
        return $this->success($this->imports->show($this->user(), $import));
    }

    public function importErrors(string $import)
    {
        return $this->success($this->imports->errors($this->user(), $import));
    }

    public function cancelImport(string $import)
    {
        return $this->success($this->imports->cancel($this->user(), $import));
    }

    public function health(?string $component = null)
    {
        return $this->success($this->operations->health($this->user(), $component));
    }

    public function tasks()
    {
        return $this->success($this->operations->tasks($this->user()));
    }

    public function alerts()
    {
        return $this->success($this->operations->alerts($this->user()));
    }

    public function alert(string $alert, string $state)
    {
        return $this->success($this->operations->alertState($this->user(), $alert, $state));
    }

    public function preferences(Request $request)
    {
        return $this->success($this->operations->preferences($this->user(), $request->isMethod('put') ? $request->validate(['dashboard_widgets' => 'sometimes|array|max:30', 'default_page_size' => 'sometimes|integer|min:10|max:100', 'timezone' => 'sometimes|timezone', 'language' => 'sometimes|string|max:10', 'notification_preferences' => 'sometimes|array', 'digest_frequency' => 'sometimes|in:never,daily,weekly', 'default_audit_range_days' => 'sometimes|integer|min:1|max:365', 'preferred_dashboard' => 'sometimes|in:school,platform', 'show_system_health' => 'sometimes|boolean']) : null));
    }

    public function onboardSchool(
        Request $request,
        PlatformSchoolOnboardingService $onboarding
    ) {
        $validated = $request->validate([
            'school_name' => 'required|string|max:255',

            'school_code' => 'prohibited',

            'timezone' => 'nullable|string|max:60',

            'locale' => 'nullable|string|max:10',

            'admin' => 'required|array',

            'admin.first_name' => 'required|string|max:100',

            'admin.last_name' => 'required|string|max:100',

            'admin.email' => 'required|email|max:255|unique:users,email',

            'admin.username' => 'prohibited',

            'admin.temporary_password' => 'prohibited',

            'admin.role_id' => 'prohibited',

            'admin.school_id' => 'prohibited',
        ]);

        return response()->json(
            [
                'success' => true,
                'data' => $onboarding->onboard(
                    $this->user(),
                    $validated
                ),
            ],
            201
        );
    }

    public function reports(Request $request)
    {
        $data = $request->isMethod('get') ? [] : $request->validate(['report_type' => 'required|string', 'filters' => 'sometimes|array']);

        return $this->success($this->operations->reports($this->user(), $data['report_type'] ?? null, $request->routeIs('admin.reports.generate'), $data['filters'] ?? []));
    }

    private function schoolRules(): array
    {
        return ['school_name' => 'sometimes|string|max:255', 'short_name' => 'sometimes|nullable|string|max:100', 'registration_number' => 'sometimes|nullable|string|max:100', 'school_type' => 'sometimes|nullable|string|max:100', 'postal_address' => 'sometimes|nullable|string|max:500', 'physical_address' => 'sometimes|nullable|string|max:500', 'county' => 'sometimes|nullable|string|max:100', 'sub_county' => 'sometimes|nullable|string|max:100', 'phone' => 'sometimes|nullable|string|max:30', 'email' => 'sometimes|nullable|email|max:255', 'website' => 'sometimes|nullable|url|max:255', 'motto' => 'sometimes|nullable|string|max:255', 'timezone' => 'sometimes|timezone', 'locale' => 'sometimes|string|max:10', 'academic_contact' => 'sometimes|nullable|string|max:255', 'finance_contact' => 'sometimes|nullable|string|max:255', 'communication_contact' => 'sometimes|nullable|string|max:255'];
    }

    private function userRules(bool $update = false): array
    {
        $required = $update ? 'sometimes' : 'required';

        return ['role_id' => $required.'|uuid', 'username' => $required.'|string|max:120', 'email' => 'sometimes|nullable|email|max:255', 'phone' => 'sometimes|nullable|string|max:30', 'first_name' => $required.'|string|max:120', 'middle_name' => 'sometimes|nullable|string|max:120', 'last_name' => $required.'|string|max:120'];
    }
}
