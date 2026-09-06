<?php

namespace App\Services\Administrator;

use App\Contracts\ParentPortal\PaymentProviderInterface;
use App\Models\School;
use App\Models\User;
use App\Services\Communication\ProviderHealthService;
use App\Services\SchoolSetupReadinessService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class AdministratorPortalService
{
    private const MODULES = ['teaching', 'assessment', 'attendance', 'timetable', 'finance', 'communication', 'parent_portal', 'teacher_portal', 'learner_portal', 'leadership_portal', 'behaviour', 'elections', 'homework', 'learning_resources'];

    public function __construct(
        private AdministratorPortalAccessService $access,
        private AdministratorAuditService $audit,
        private ProviderHealthService $communicationHealth,
        private PaymentProviderInterface $paymentProvider,
        private SchoolSetupReadinessService $setupReadiness,
    ) {}

    public function dashboard(User $user, bool $platform = false): array
    {
        if ($platform) {
            $this->access->requirePlatform($user, 'view_platform_dashboard');

            return $this->platformDashboard();
        }
        $school = $this->access->school($user);
        $schoolId = $school->id;
        $subscription = $this->subscription($user);

        return [
            'scope' => 'school', 'school' => $this->safeSchool($school), 'profile_completeness' => $this->completeness($user),
            'subscription' => $subscription, 'active_users_by_role' => $this->usersByRole($schoolId),
            'learner_count' => $this->count('learners', $schoolId), 'teacher_count' => $this->count('teachers', $schoolId),
            'current_academic_year' => $this->current('academic_years', $schoolId), 'current_term' => $this->current('terms', $schoolId),
            'pending_user_actions' => DB::table('users')->where('school_id', $schoolId)->where(fn ($q) => $q->where('active', false)->orWhereNotNull('account_locked_until'))->count(),
            'pending_imports' => DB::table('administrator_imports')->where('school_id', $schoolId)->whereIn('status', ['previewed', 'queued', 'processing'])->count(),
            'critical_alerts' => DB::table('administrator_alerts')->where('school_id', $schoolId)->where('status', 'open')->where('severity', 'critical')->count(),
            'queue_health' => ['pending' => Schema::hasTable('jobs') ? DB::table('jobs')->count() : 0, 'failed' => Schema::hasTable('failed_jobs') ? DB::table('failed_jobs')->count() : 0],
            'storage' => ['private_storage_writable' => is_writable(storage_path('app')), 'free_bytes' => @disk_free_space(storage_path('app')) ?: null],
            'provider_readiness' => $this->providerReadiness($user), 'module_readiness' => $this->modules($user),
            'recent_audit_events' => DB::table('audit_logs')->where('school_id', $schoolId)->select('id', 'module', 'action', 'table_name', 'record_id', 'description', 'created_at')->latest('created_at')->limit(10)->get(),
            'system_notices' => [],
            'last_refreshed_at' => now()->toIso8601String(),
        ];
    }

    public function platformDashboard(): array
    {
        $states = DB::table('schools')->where('is_deleted', false)->selectRaw('lifecycle_state, COUNT(*) AS total')->groupBy('lifecycle_state')->pluck('total', 'lifecycle_state');

        return [
            'scope' => 'platform', 'schools_by_lifecycle' => $states,
            'total_schools' => DB::table('schools')->where('is_deleted', false)->count(),
            'total_users_by_role' => DB::table('users')->join('roles', 'roles.id', '=', 'users.role_id')->where('users.is_deleted', false)->selectRaw('roles.role_name, COUNT(*) AS total')->groupBy('roles.role_name')->pluck('total', 'roles.role_name'),
            'total_learners' => DB::table('learners')->where('is_deleted', false)->count(), 'total_teachers' => DB::table('teachers')->where('is_deleted', false)->count(),
            'subscription_distribution' => DB::table('school_subscriptions')->leftJoin('subscription_packages', 'subscription_packages.id', '=', 'school_subscriptions.package_id')->selectRaw("COALESCE(subscription_packages.package_name, 'Unconfigured') AS plan, COUNT(*) AS total")->groupBy('subscription_packages.package_name')->pluck('total', 'plan'),
            'upcoming_expiries' => DB::table('school_subscriptions')->whereBetween('expiry_date', [now(), now()->addDays(30)])->count(),
            'failed_provisioning_tasks' => DB::table('administrator_imports')->where('status', 'failed')->count(),
            'system_alerts' => DB::table('administrator_alerts')->whereNull('school_id')->where('status', 'open')->select('id', 'type', 'severity', 'title', 'created_at')->limit(20)->get(),
            'last_refreshed_at' => now()->toIso8601String(),
        ];
    }

    public function updateSchool(User $user, array $data): School
    {
        $school = $this->access->school($user);
        $allowed = ['school_name', 'short_name', 'registration_number', 'school_type', 'postal_address', 'physical_address', 'county', 'sub_county', 'phone', 'email', 'website', 'motto', 'timezone', 'locale', 'academic_contact', 'finance_contact', 'communication_contact'];
        foreach (['email', 'phone'] as $verifiedContact) {
            if (array_key_exists($verifiedContact, $data) && filled($school->{$verifiedContact}) && $data[$verifiedContact] !== $school->{$verifiedContact}) {
                throw ValidationException::withMessages([$verifiedContact => 'Use the verified contact-change workflow to replace this contact.']);
            }
        }
        $old = collect($school->toArray())->only($allowed)->all();
        $school->update(collect($data)->only($allowed)->map(fn ($value) => is_string($value) ? strip_tags($value) : $value)->all());
        $this->audit->record($user, 'school_profile_updated', 'schools', $school->id, $old, collect($school->fresh()->toArray())->only($allowed)->all());

        return $school->fresh();
    }

    public function completeness(User $user): array
    {
        $school = $this->access->school($user);
        $fields = ['school_name', 'short_name', 'registration_number', 'school_type', 'county', 'phone', 'email', 'timezone', 'locale'];
        $missing = collect($fields)->filter(fn ($field) => blank($school->{$field}))->values();
        $setup = $this->academicSetup($user);
        $missing = $missing->merge(collect($setup['missing'])->map(fn ($item) => 'academic:'.$item));

        $profileMissing = collect($fields)->filter(fn ($field) => blank($school->{$field}))->count();

        return ['percentage' => round((count($fields) - $profileMissing) * 100 / count($fields), 2), 'missing' => $missing->values(), 'complete' => $missing->isEmpty()];
    }

    public function initialSetup(User $user): array
    {
        $school = $this->access->school($user);

        return $this->setupReadiness->readiness(
            (string) $school->id
        );
    }

    public function academicSetup(User $user): array
    {
        $schoolId = $this->access->scope($user)['school_id'];
        $checks = [
            'active_academic_year' => DB::table('academic_years')->where('school_id', $schoolId)->where('active', true)->exists(),
            'active_term' => DB::table('terms')->where('school_id', $schoolId)->where('active', true)->exists(),
            'grades' => DB::table('grades')->where('school_id', $schoolId)->where('active', true)->exists(),
            'streams' => DB::table('streams')->where('school_id', $schoolId)->where('active', true)->exists(),
            'learning_areas' => DB::table('learning_area_allocations')->where('school_id', $schoolId)->where('active', true)->exists(),
        ];

        return ['checks' => $checks, 'missing' => collect($checks)->filter(fn ($ready) => ! $ready)->keys()->values(), 'complete' => ! in_array(false, $checks, true)];
    }

    public function academic(User $user, string $table)
    {
        abort_unless(in_array($table, ['academic_years', 'terms', 'grades', 'streams', 'learning_areas'], true), 404);
        $schoolId = $this->access->scope($user)['school_id'];
        if ($table === 'learning_areas') {
            return DB::table('learning_areas')->join('learning_area_allocations', 'learning_area_allocations.learning_area_id', '=', 'learning_areas.id')->where('learning_area_allocations.school_id', $schoolId)->where('learning_area_allocations.active', true)->select('learning_areas.id', 'learning_areas.learning_area_name', 'learning_areas.short_name', 'learning_areas.active')->distinct()->orderBy('learning_areas.learning_area_name')->limit(200)->get();
        }
        $columns = Schema::getColumnListing($table);
        $safe = array_values(array_intersect($columns, ['id', 'academic_year_name', 'year_name', 'term_name', 'grade_name', 'stream_name', 'learning_area_name', 'short_name', 'active', 'start_date', 'end_date']));

        return DB::table($table)->where('school_id', $schoolId)->select($safe ?: ['id'])->orderBy($safe[1] ?? 'id')->limit(200)->get();
    }

    public function subscription(User $user, ?string $platformSchoolId = null): array
    {
        $school = $platformSchoolId ? $this->access->platformSchool($user, $platformSchoolId) : $this->access->school($user);
        $row = DB::table('school_subscriptions')->leftJoin('subscription_packages', 'subscription_packages.id', '=', 'school_subscriptions.package_id')->where('school_subscriptions.school_id', $school->id)
            ->select('school_subscriptions.*', 'subscription_packages.package_name as plan_name')->latest('school_subscriptions.created_at')->first();
        $legacy = DB::table('school_settings')->where('school_id', $school->id)->first();
        $status = $row?->status ?? $legacy?->subscription_status ?? 'unconfigured';

        $enabledModules = collect(self::MODULES)->filter(function ($module) use ($legacy) {
            $column = match ($module) {
                'finance', 'attendance', 'timetable', 'elections' => $module.'_enabled',
                'parent_portal', 'learner_portal' => $module.'_enabled',
                default => null,
            };

            return $column === null || (bool) ($legacy->{$column} ?? true);
        })->values()->all();

        return ['school_id' => $platformSchoolId ? $school->id : null, 'plan' => $row?->plan_name ?? $legacy?->subscription_package, 'status' => $status, 'trial_starts_at' => $row?->is_trial ? $row->start_date : null, 'trial_ends_at' => $row?->is_trial ? $row->expiry_date : null, 'starts_at' => $row?->start_date, 'ends_at' => $row?->expiry_date, 'grace_ends_at' => $row?->grace_end_date, 'lifecycle_state' => $school->lifecycle_state, 'enabled_modules' => $enabledModules, 'renewal_needed' => $row?->expiry_date && now()->addDays(30)->gte($row->expiry_date), 'read_only' => $school->lifecycle_state === 'read_only', 'locked' => $school->lifecycle_state === 'locked'];
    }

    public function subscriptionHistory(User $user): mixed
    {
        $schoolId = $this->access->scope($user)['school_id'];

        return DB::table('school_subscription_history')->where('school_id', $schoolId)->select('id', 'status', 'safe_snapshot', 'created_at')->latest('created_at')->limit(100)->get();
    }

    public function modules(User $user, ?string $module = null): array
    {
        $schoolId = $this->access->scope($user)['school_id'];
        $settings = DB::table('school_settings')->where('school_id', $schoolId)->first();
        $items = collect(self::MODULES)->map(function ($name) use ($settings) {
            $setting = str_replace('_portal', '_portal_enabled', $name);
            $setting = match ($name) {
                'finance', 'attendance', 'timetable', 'elections' => $name.'_enabled', default => $setting
            };
            $enabled = property_exists($settings ?: (object) [], $setting) ? (bool) $settings->{$setting} : true;

            return ['module' => $name, 'enabled' => $enabled, 'ready' => $enabled, 'configuration_complete' => $enabled, 'missing' => $enabled ? [] : ['module_disabled'], 'safe_link' => '/admin/modules/'.$name];
        });
        if ($module) {
            $item = $items->firstWhere('module', $module);
            abort_unless($item, 404);

            return $item;
        }

        return $items->values()->all();
    }

    public function providerReadiness(User $user): array
    {
        $this->access->scope($user);

        return ['communication' => $this->communicationHealth->status(), 'payment' => ['provider' => config('parent_portal_phase_two.payment_provider'), 'ready' => $this->paymentProvider->ready()]];
    }

    public function safeSchool(School $school): array
    {
        return collect($school->toArray())->only(['id', 'school_name', 'short_name', 'school_code', 'email', 'phone', 'county', 'sub_county', 'postal_address', 'physical_address', 'school_type', 'ownership', 'registration_number', 'website', 'motto', 'timezone', 'locale', 'academic_contact', 'finance_contact', 'communication_contact', 'lifecycle_state', 'active', 'created_at', 'updated_at'])->all();
    }

    private function usersByRole(string $schoolId): mixed
    {
        return DB::table('users')->join('roles', 'roles.id', '=', 'users.role_id')->where('users.school_id', $schoolId)->where('users.active', true)->where('users.is_deleted', false)->selectRaw('roles.role_name, COUNT(*) AS total')->groupBy('roles.role_name')->pluck('total', 'roles.role_name');
    }

    private function count(string $table, string $schoolId): int
    {
        return Schema::hasTable($table) ? DB::table($table)->where('school_id', $schoolId)->where('active', true)->where('is_deleted', false)->count() : 0;
    }

    private function current(string $table, string $schoolId): ?object
    {
        return Schema::hasTable($table) ? DB::table($table)->where('school_id', $schoolId)->where('active', true)->select('id', ...array_values(array_intersect(Schema::getColumnListing($table), ['academic_year_name', 'year_name', 'term_name', 'start_date', 'end_date'])))->first() : null;
    }
}
