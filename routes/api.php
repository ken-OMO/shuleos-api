<?php

use App\Http\Controllers\Api\AcademicWeekController;
use App\Http\Controllers\Api\AcademicYearController;
use App\Http\Controllers\Api\AdministratorOperationsController;
use App\Http\Controllers\Api\AdministratorPortalController;
use App\Http\Controllers\Api\AssessmentRegistrationController;
use App\Http\Controllers\Api\AssessmentTypeController;
use App\Http\Controllers\Api\AttendanceAlertController;
use App\Http\Controllers\Api\AttendanceLeadershipController;
use App\Http\Controllers\Api\AttendanceLearnerController;
use App\Http\Controllers\Api\AttendanceSessionController;
use App\Http\Controllers\Api\AttendanceStatusController;
use App\Http\Controllers\Api\AttendanceTeacherController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BehaviourLeadershipController;
use App\Http\Controllers\Api\BehaviourLearnerController;
use App\Http\Controllers\Api\BehaviourParentController;
use App\Http\Controllers\Api\BehaviourTeacherController;
use App\Http\Controllers\Api\CommunicationController;
use App\Http\Controllers\Api\CommunicationPhaseTwoController;
use App\Http\Controllers\Api\CommunicationWebhookController;
use App\Http\Controllers\Api\CurriculumCoverageController;
use App\Http\Controllers\Api\ExamController;
use App\Http\Controllers\Api\ExamLearningAreaController;
use App\Http\Controllers\Api\ExamPaperController;
use App\Http\Controllers\Api\ExamResultController;
use App\Http\Controllers\Api\FeeCategoryController;
use App\Http\Controllers\Api\FeeInvoiceController;
use App\Http\Controllers\Api\FeeStructureController;
use App\Http\Controllers\Api\FinancePhaseTwoController;
use App\Http\Controllers\Api\FinancePortalController;
use App\Http\Controllers\Api\FinanceSettingController;
use App\Http\Controllers\Api\FinanceWorkflowController;
use App\Http\Controllers\Api\GradeController;
use App\Http\Controllers\Api\GuardianController;
use App\Http\Controllers\Api\GuardianLinkController;
use App\Http\Controllers\Api\HomeworkLeadershipController;
use App\Http\Controllers\Api\HomeworkLearnerController;
use App\Http\Controllers\Api\HomeworkTeacherController;
use App\Http\Controllers\Api\HostelBedController;
use App\Http\Controllers\Api\HostelController;
use App\Http\Controllers\Api\HostelRoomController;
use App\Http\Controllers\Api\LeadershipPortalController;
use App\Http\Controllers\Api\LeadershipPortalPhaseTwoController;
use App\Http\Controllers\Api\LearnerAttendanceController;
use App\Http\Controllers\Api\LearnerController;
use App\Http\Controllers\Api\LearnerFeeAccountController;
use App\Http\Controllers\Api\LearnerLifecycleController;
use App\Http\Controllers\Api\LearnerModeOfStudyController;
use App\Http\Controllers\Api\LearnerPlacementController;
use App\Http\Controllers\Api\LearnerPortalAdminController;
use App\Http\Controllers\Api\LearnerPortalController;
use App\Http\Controllers\Api\LearnerPortalPhaseTwoController;
use App\Http\Controllers\Api\LearningAreaAllocationController;
use App\Http\Controllers\Api\LearningAreaController;
use App\Http\Controllers\Api\LearningAreaResultController;
use App\Http\Controllers\Api\LearningResourceAdminController;
use App\Http\Controllers\Api\LearningResourceCategoryController;
use App\Http\Controllers\Api\LearningResourceLearnerController;
use App\Http\Controllers\Api\LearningResourceTeacherController;
use App\Http\Controllers\Api\LessonNoteController;
use App\Http\Controllers\Api\LessonPlanController;
use App\Http\Controllers\Api\MarkEntryPermissionController;
use App\Http\Controllers\Api\MeritListController;
use App\Http\Controllers\Api\ParentPaymentWebhookController;
use App\Http\Controllers\Api\ParentPortalAdminController;
use App\Http\Controllers\Api\ParentPortalMobileController;
use App\Http\Controllers\Api\ParentPortalPhaseTwoController;
use App\Http\Controllers\Api\PaymentAllocationController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\PaymentMethodController;
use App\Http\Controllers\Api\PaymentPlanController;
use App\Http\Controllers\Api\PlatformAuthController;
use App\Http\Controllers\Api\RecordOfWorkController;
use App\Http\Controllers\Api\ReportCardController;
use App\Http\Controllers\Api\ReportCardPdfController;
use App\Http\Controllers\Api\RoomConstraintController;
use App\Http\Controllers\Api\RoomController;
use App\Http\Controllers\Api\RoomTypeController;
use App\Http\Controllers\Api\SchemeLessonController;
use App\Http\Controllers\Api\SchemeOfWorkController;
use App\Http\Controllers\Api\SchoolController;
use App\Http\Controllers\Api\SmartTimetableAutomationController;
use App\Http\Controllers\Api\SmartTimetableController;
use App\Http\Controllers\Api\StreamController;
use App\Http\Controllers\Api\StudentElectionAdminController;
use App\Http\Controllers\Api\StudentElectionLearnerController;
use App\Http\Controllers\Api\TeacherAssignmentController;
use App\Http\Controllers\Api\TeacherAvailabilityController;
use App\Http\Controllers\Api\TeacherConstraintController;
use App\Http\Controllers\Api\TeacherController;
use App\Http\Controllers\Api\TeacherPortalController;
use App\Http\Controllers\Api\TeacherPortalMobileController;
use App\Http\Controllers\Api\TeacherPortalPhaseTwoController;
use App\Http\Controllers\Api\TermController;
use App\Http\Controllers\Api\TimetableConflictController;
use App\Http\Controllers\Api\TimetableConstraintController;
use App\Http\Controllers\Api\TimetableEntryController;
use App\Http\Controllers\Api\TimetableGenerationRunController;
use App\Http\Controllers\Api\TimetablePeriodController;
use App\Http\Controllers\Api\TimetableProfileController;
use App\Http\Controllers\Api\TimetablePublicationController;
use App\Http\Controllers\Api\TimetableSubstitutionController;
use App\Http\Controllers\Api\UserController;
use App\Http\Middleware\TeacherAcademicScope;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Global Middleware
|--------------------------------------------------------------------------
*/

$authenticated = [
    'jwt',
];
$secure = [

    'jwt',

    'tenant',

    'module.permission',

];

Route::post(
    '/auth/first-login/verify-otp',
    [
        AuthController::class,
        'verifyFirstLoginOtp',
    ]
)->middleware('throttle:10,1');
Route::post(
    '/auth/first-login/activate',
    [
        AuthController::class,
        'activateFirstLogin',
    ]
)->middleware('throttle:5,1');
Route::prefix('platform/auth')->group(function () {
    Route::post(
        '/login',
        [
            PlatformAuthController::class,
            'login',
        ]
    )->middleware('throttle:5,1');

    Route::post(
        '/verify-otp',
        [
            PlatformAuthController::class,
            'verifyOtp',
        ]
    )->middleware('throttle:10,1');

    Route::post(
        '/activate',
        [
            PlatformAuthController::class,
            'activate',
        ]
    )->middleware('throttle:5,1');
});

Route::prefix('admin')
    ->middleware($authenticated)
    ->group(function () {
        Route::get(
            '/dashboard/platform',
            [AdministratorPortalController::class, 'dashboard']
        )
            ->middleware([
                'permission:access_platform_administration',
                'permission:view_platform_dashboard',
            ])
            ->name('admin.dashboard.platform');
        Route::get(
            '/platform/subscriptions',
            [AdministratorPortalController::class, 'subscription']
        )
            ->middleware([
                'permission:access_platform_administration',
                'permission:view_platform_subscriptions',
            ])
            ->name('admin.platform.subscriptions');

        Route::get(
            '/platform/subscriptions/{school}',
            [AdministratorPortalController::class, 'subscription']
        )
            ->middleware([
                'permission:access_platform_administration',
                'permission:view_platform_subscriptions',
            ]);

        Route::get(
            '/platform/schools',
            [AdministratorPortalController::class, 'platformSchools']
        )
            ->middleware([
                'permission:access_platform_administration',
                'permission:manage_school_lifecycle',
            ]);
        Route::post(
            '/platform/schools',
            [AdministratorPortalController::class, 'onboardSchool']
        )
            ->middleware([
                'permission:access_platform_administration',
                'permission:onboard_schools',
            ]);

        Route::get(
            '/platform/schools/{school}',
            [AdministratorPortalController::class, 'platformSchool']
        )
            ->middleware([
                'permission:access_platform_administration',
                'permission:manage_school_lifecycle',
            ]);

        foreach (
            [
                'activate',
                'suspend',
                'resume',
                'enter-read-only',
                'lock',
                'archive',
            ] as $action
        ) {
            Route::post(
                '/platform/schools/{school}/'.$action,
                fn (
                    Request $request,
                    string $school
                ) => app(
                    AdministratorPortalController::class
                )->lifecycle(
                    $request,
                    $school,
                    $action
                )
            )->middleware([
                'permission:access_platform_administration',
                'permission:manage_school_lifecycle',
            ]);
        }
    });
Route::prefix('admin')->middleware($secure)->group(function () {
    Route::get('/dashboard', [AdministratorPortalController::class, 'dashboard'])->middleware('permission:view_admin_dashboard');
    Route::get('/dashboard/school', [AdministratorPortalController::class, 'dashboard'])->middleware('permission:view_admin_dashboard');

    Route::get('/school', [AdministratorPortalController::class, 'school'])->middleware('permission:manage_school_profile');
    Route::get('/school/setup', [AdministratorPortalController::class, 'initialSetup'])->middleware('permission:view_academic_setup_status');
    Route::put('/school', [AdministratorPortalController::class, 'updateSchool'])->middleware('permission:manage_school_profile');
    Route::put('/school/complete-profile', [AdministratorPortalController::class, 'completeSchoolProfile'])->middleware('permission:manage_school_profile');
    Route::get('/school/completeness', [AdministratorPortalController::class, 'completeness'])->middleware('permission:view_school_completeness');

    Route::get('/users', [AdministratorPortalController::class, 'users'])->middleware('permission:view_school_users');
    Route::post('/users', [AdministratorPortalController::class, 'userCreate'])->middleware('permission:create_school_users');
    Route::get('/users/{user}', [AdministratorPortalController::class, 'userShow'])->middleware('permission:view_school_users');
    Route::put('/users/{user}', [AdministratorPortalController::class, 'userUpdate'])->middleware('permission:update_school_users');
    foreach (['activate', 'suspend', 'unlock', 'force-password-reset', 'revoke-sessions', 'revoke-devices'] as $action) {
        $permission = match ($action) {
            'activate' => 'activate_school_users', 'suspend' => 'suspend_school_users', 'unlock' => 'unlock_school_users', 'force-password-reset' => 'force_school_user_password_reset', 'revoke-sessions' => 'revoke_school_user_sessions', default => 'revoke_school_user_devices'
        };
        Route::post('/users/{user}/'.$action, fn (string $user) => app(AdministratorPortalController::class)->userAction($user, $action))->middleware('permission:'.$permission);
    }

    Route::get('/roles', [AdministratorPortalController::class, 'roles'])->middleware('permission:view_roles_and_permissions');
    Route::post('/roles', [AdministratorPortalController::class, 'createRole'])->middleware('permission:manage_school_roles');
    Route::get('/roles/{role}', [AdministratorPortalController::class, 'role'])->middleware('permission:view_roles_and_permissions');
    Route::put('/roles/{role}', [AdministratorPortalController::class, 'updateRole'])->middleware('permission:manage_school_roles');
    Route::post('/roles/{role}/permissions', [AdministratorPortalController::class, 'rolePermissions'])->middleware('permission:assign_school_permissions');
    Route::get('/permissions', [AdministratorPortalController::class, 'permissions'])->middleware('permission:view_roles_and_permissions');
    Route::get('/permissions/modules', [AdministratorPortalController::class, 'permissions'])->middleware('permission:view_roles_and_permissions')->name('admin.permissions.modules');

    foreach (['academic-years', 'terms', 'grades', 'streams', 'learning-areas'] as $type) {
        Route::get('/'.$type, fn () => app(AdministratorPortalController::class)->academic($type))->middleware('permission:view_academic_setup_status');
    }
    Route::get('/academic-setup/status', fn () => app(AdministratorPortalController::class)->academic('status'))->middleware('permission:view_academic_setup_status');
    Route::get('/branding', [AdministratorPortalController::class, 'branding'])->middleware('permission:manage_school_branding');
    Route::put('/branding', [AdministratorPortalController::class, 'updateBranding'])->middleware('permission:manage_school_branding');
    Route::post('/branding/uploads', [AdministratorPortalController::class, 'uploadBranding'])->middleware('permission:manage_school_branding');
    Route::delete('/branding/uploads/{asset}', [AdministratorPortalController::class, 'archiveBranding'])->middleware('permission:manage_school_branding');

    Route::get('/subscription', [AdministratorPortalController::class, 'subscription'])->middleware('permission:view_school_subscription');
    Route::get('/subscription/history', [AdministratorPortalController::class, 'subscription'])->middleware('permission:view_school_subscription')->name('admin.subscription.history');
    Route::get('/subscription/entitlements', [AdministratorPortalController::class, 'subscription'])->middleware('permission:view_school_subscription')->name('admin.subscription.entitlements');

    Route::get('/modules', [AdministratorPortalController::class, 'modules'])->middleware('permission:view_module_readiness');
    Route::get('/modules/{module}', [AdministratorPortalController::class, 'modules'])->middleware('permission:view_module_readiness');

    Route::get('/audit/summary', [AdministratorPortalController::class, 'audit'])->middleware('permission:view_admin_audit')->name('admin.audit.summary');
    Route::get('/audit', [AdministratorPortalController::class, 'audit'])->middleware('permission:view_admin_audit');
    Route::get('/audit/{event}', [AdministratorPortalController::class, 'audit'])->middleware('permission:view_admin_audit');
    foreach (['summary', 'logins', 'locked-users', 'devices', 'sessions'] as $view) {
        Route::get('/security/'.$view, fn () => app(AdministratorPortalController::class)->security($view))->middleware('permission:view_admin_security');
    }
    foreach (['revoke-sessions', 'revoke-devices'] as $action) {
        Route::post('/security/users/{user}/'.$action, fn (string $user) => app(AdministratorPortalController::class)->securityUserAction($user, $action))->middleware('permission:manage_admin_security_actions');
    }
    Route::get('/devices', [AdministratorPortalController::class, 'devices'])->middleware('permission:view_school_devices');
    Route::get('/devices/{device}', [AdministratorPortalController::class, 'devices'])->middleware('permission:view_school_devices');
    Route::post('/devices/{device}/revoke', [AdministratorPortalController::class, 'revokeDevice'])->middleware('permission:revoke_school_devices');

    foreach (['summary', 'policies', 'provider-health', 'failures', 'suppressed-contacts'] as $view) {
        Route::get('/communications/'.$view, fn (Request $request) => app(AdministratorPortalController::class)->communications($request, $view))->middleware('permission:view_provider_readiness');
    }
    Route::put('/communications/policies', fn (Request $request) => app(AdministratorPortalController::class)->communications($request, 'policies-update'))->middleware('permission:manage_communication_policy');
    foreach (['provider-health', 'settings', 'reconciliation-summary'] as $view) {
        Route::get('/payments/'.$view, fn () => app(AdministratorPortalController::class)->payments($view))->middleware('permission:view_payment_reconciliation_summary');
    }

    Route::get('/imports', [AdministratorPortalController::class, 'imports'])->middleware('permission:manage_data_imports');
    Route::post('/imports/preview', [AdministratorPortalController::class, 'previewImport'])->middleware('permission:manage_data_imports');
    Route::post('/imports', [AdministratorPortalController::class, 'queueImport'])->middleware('permission:manage_data_imports');
    Route::get('/imports/{import}', [AdministratorPortalController::class, 'import'])->middleware('permission:manage_data_imports');
    Route::get('/imports/{import}/errors', [AdministratorPortalController::class, 'importErrors'])->middleware('permission:manage_data_imports');
    Route::post('/imports/{import}/cancel', [AdministratorPortalController::class, 'cancelImport'])->middleware('permission:manage_data_imports');

    Route::get('/system-health', [AdministratorPortalController::class, 'health'])->middleware('permission:view_system_health');
    foreach (['database', 'queue', 'scheduler', 'storage', 'cache'] as $component) {
        Route::get('/system-health/'.$component, fn () => app(AdministratorPortalController::class)->health($component))->middleware('permission:view_system_health');
    }
    Route::get('/tasks', [AdministratorPortalController::class, 'tasks'])->middleware('permission:view_admin_tasks');
    Route::get('/alerts', [AdministratorPortalController::class, 'alerts'])->middleware('permission:view_admin_alerts');
    foreach (['acknowledge', 'dismiss'] as $state) {
        Route::post('/alerts/{alert}/'.$state, fn (string $alert) => app(AdministratorPortalController::class)->alert($alert, $state))->middleware('permission:acknowledge_admin_alerts');
    }
    Route::get('/preferences', [AdministratorPortalController::class, 'preferences'])->middleware('permission:manage_admin_preferences');
    Route::put('/preferences', [AdministratorPortalController::class, 'preferences'])->middleware('permission:manage_admin_preferences');
    Route::get('/reports', [AdministratorPortalController::class, 'reports'])->middleware('permission:view_admin_reports');
    Route::post('/reports/preview', [AdministratorPortalController::class, 'reports'])->middleware('permission:view_admin_reports');
    Route::post('/reports/generate', [AdministratorPortalController::class, 'reports'])->middleware('permission:generate_admin_reports')->name('admin.reports.generate');
});

/*
|--------------------------------------------------------------------------
| Platform Operations Routes
|--------------------------------------------------------------------------
|
| Platform infrastructure is global and must never pass through
| TenantMiddleware. Platform identities have school_id = NULL.
|
*/

Route::prefix('admin/operations')
    ->middleware([
        'jwt',
        'permission:access_platform_administration',
    ])
    ->group(function () {
        Route::get('/queue', fn () => app(AdministratorOperationsController::class)->queue())
            ->middleware('permission:view_queue_operations');

        Route::get('/scheduler', fn () => app(AdministratorOperationsController::class)->scheduler())
            ->middleware('permission:view_scheduler_operations');

        Route::get('/cache', [AdministratorOperationsController::class, 'cache'])
            ->middleware('permission:view_cache_operations');

        Route::get('/logs', [AdministratorOperationsController::class, 'logs'])
            ->middleware('permission:view_application_logs');

        Route::get('/storage', fn () => app(AdministratorOperationsController::class)->storage())
            ->middleware('permission:view_storage_operations');

        Route::get('/backups', [AdministratorOperationsController::class, 'backups'])
            ->middleware('permission:view_backup_operations');

        Route::get('/restores', [AdministratorOperationsController::class, 'restores'])
            ->middleware('permission:view_restore_operations');

        Route::get('/diagnostics', [AdministratorOperationsController::class, 'diagnostics'])
            ->middleware('permission:view_operational_diagnostics');

        Route::get('/releases', [AdministratorOperationsController::class, 'releases'])
            ->middleware('permission:view_release_metadata');

        Route::get('/platform-settings', [AdministratorOperationsController::class, 'settings'])
            ->middleware('permission:manage_platform_settings');

        Route::get('/disaster-recovery', [AdministratorOperationsController::class, 'disasterRecovery'])
            ->middleware('permission:view_disaster_recovery_readiness');
        /*
        |--------------------------------------------------------------------------
        | Controlled Platform Mutations
        |--------------------------------------------------------------------------
        */

        Route::post(
            '/queue/failed/{job}/retry',
            fn (Request $request, string $job) => app(
                AdministratorOperationsController::class
            )->queueAction($request, $job, 'retry')
        )->middleware('permission:retry_failed_jobs');

        Route::post(
            '/queue/failed/{job}/forget',
            fn (Request $request, string $job) => app(
                AdministratorOperationsController::class
            )->queueAction($request, $job, 'forget')
        )->middleware('permission:forget_failed_jobs');

        Route::post(
            '/scheduler/tasks/{task}/run',
            [AdministratorOperationsController::class, 'runTask']
        )->middleware(
            'permission:run_allowlisted_scheduler_tasks'
        );

        Route::post(
            '/cache/preview-clear',
            [AdministratorOperationsController::class, 'cachePreview']
        )->middleware(
            'permission:clear_safe_cache_groups'
        );

        Route::post(
            '/cache/clear',
            [AdministratorOperationsController::class, 'cacheClear']
        )->middleware(
            'permission:clear_safe_cache_groups'
        );

        Route::post(
            '/diagnostics/run',
            [AdministratorOperationsController::class, 'runDiagnostics']
        )->middleware(
            'permission:run_operational_diagnostics'
        );

        Route::put(
            '/platform-settings',
            [AdministratorOperationsController::class, 'settings']
        )->middleware(
            'permission:manage_platform_settings'
        );
        /*
        |--------------------------------------------------------------------------
        | Platform Storage Mutations
        |--------------------------------------------------------------------------
        */

        foreach (['release', 'reject'] as $action) {
            Route::post(
                '/storage/quarantine/{file}/'.$action,
                fn (Request $request, string $file) => app(
                    AdministratorOperationsController::class
                )->storageAction(
                    $request,
                    $file,
                    $action
                )
            )->middleware(
                'permission:manage_quarantined_files'
            );
        }

        Route::post(
            '/storage/orphans/{file}/archive',
            fn (Request $request, string $file) => app(
                AdministratorOperationsController::class
            )->storageAction(
                $request,
                $file,
                'archive'
            )
        )->middleware(
            'permission:manage_quarantined_files'
        );
        /*
        |--------------------------------------------------------------------------
        | Platform Restore Mutations
        |--------------------------------------------------------------------------
        */

        Route::post(
            '/restores/preview',
            [AdministratorOperationsController::class, 'restorePreview']
        )->middleware(
            'permission:create_restore_requests'
        );

        Route::post(
            '/restores',
            [AdministratorOperationsController::class, 'createRestore']
        )->middleware(
            'permission:create_restore_requests'
        );

        Route::post(
            '/restores/{restore}/cancel',
            [AdministratorOperationsController::class, 'cancelRestore']
        )->middleware(
            'permission:create_restore_requests'
        );
        Route::get('/queue/jobs', fn () => app(AdministratorOperationsController::class)->queue('jobs'))
            ->middleware('permission:view_queue_operations');

        Route::get('/queue/failed', fn () => app(AdministratorOperationsController::class)->queue('failed'))
            ->middleware('permission:view_queue_operations');

        Route::get('/queue/failed/{job}', fn (string $job) => app(AdministratorOperationsController::class)->queue('failed', $job))
            ->middleware('permission:view_queue_operations');

        Route::get('/scheduler/tasks', fn () => app(AdministratorOperationsController::class)->scheduler('tasks'))
            ->middleware('permission:view_scheduler_operations');

        Route::get('/scheduler/tasks/{task}', fn (string $task) => app(AdministratorOperationsController::class)->scheduler('tasks', $task))
            ->middleware('permission:view_scheduler_operations');

        Route::get('/logs/{log}', [AdministratorOperationsController::class, 'logs'])
            ->middleware('permission:view_application_logs');

        Route::get(
            '/logs/{log}/entries',
            fn (Request $request, string $log) => app(
                AdministratorOperationsController::class
            )->logs($request, $log, true)
        )->middleware('permission:view_application_logs');

        foreach (['disks', 'quarantine', 'orphans'] as $view) {
            Route::get(
                '/storage/'.$view,
                fn () => app(
                    AdministratorOperationsController::class
                )->storage($view)
            )->middleware('permission:view_storage_operations');
        }

        Route::get('/backups/{backup}', [AdministratorOperationsController::class, 'backups'])
            ->middleware('permission:view_backup_operations');

        Route::get('/restores/{restore}', [AdministratorOperationsController::class, 'restores'])
            ->middleware('permission:view_restore_operations');

        Route::get('/diagnostics/{run}', [AdministratorOperationsController::class, 'diagnostics'])
            ->middleware('permission:view_operational_diagnostics');

        Route::get('/releases/current', [AdministratorOperationsController::class, 'releases'])
            ->middleware('permission:view_release_metadata')
            ->name('admin.operations.releases.current');
    });
/*
|--------------------------------------------------------------------------
| Mixed-Scope Backup Mutations
|--------------------------------------------------------------------------
|
| Backup scope is derived from the persisted backup record. Tenant and
| platform authorization are enforced by AdministratorRecoveryService.
|
*/

Route::prefix('admin/operations')->middleware($authenticated)->group(function () {
    foreach (['verify', 'archive'] as $action) {
        Route::post(
            '/backups/{backup}/'.$action,
            fn (string $backup) => app(
                AdministratorOperationsController::class
            )->backupAction(
                $backup,
                $action
            )
        )->middleware(
            'permission:'.(
                $action === 'verify'
                    ? 'verify_backups'
                    : 'archive_backups'
            )
        );
    }
});
Route::prefix('admin/operations')->middleware($secure)->group(function () {
    Route::get('/feature-flags', [AdministratorOperationsController::class, 'flags'])->middleware('permission:manage_school_feature_flags');
    Route::post('/feature-flags', [AdministratorOperationsController::class, 'saveFlag'])->middleware('permission:manage_school_feature_flags');
    Route::get('/feature-flags/{flag}', [AdministratorOperationsController::class, 'flags'])->middleware('permission:manage_school_feature_flags');
    Route::put('/feature-flags/{flag}', [AdministratorOperationsController::class, 'saveFlag'])->middleware('permission:manage_school_feature_flags');
    foreach (['enable', 'disable', 'archive'] as $action) {
        Route::post('/feature-flags/{flag}/'.$action, fn (string $flag) => app(AdministratorOperationsController::class)->flagAction($flag, $action))->middleware('permission:manage_school_feature_flags');
    }

    Route::get('/maintenance', [AdministratorOperationsController::class, 'maintenance'])->middleware('permission:manage_school_maintenance');
    Route::post('/maintenance/preview', [AdministratorOperationsController::class, 'maintenancePreview'])->middleware('permission:manage_school_maintenance');
    Route::post('/maintenance/schedule', [AdministratorOperationsController::class, 'maintenanceSchedule'])->middleware('permission:manage_school_maintenance');
    foreach (['activate', 'deactivate', 'cancel'] as $action) {
        Route::post('/maintenance/'.$action, fn (Request $request) => app(AdministratorOperationsController::class)->maintenanceAction($request, $action))->middleware('permission:manage_school_maintenance');
    }

    Route::get('/providers', [AdministratorOperationsController::class, 'providers'])->middleware('permission:view_provider_configuration');
    Route::get('/providers/{category}', [AdministratorOperationsController::class, 'providers'])->middleware('permission:view_provider_configuration');
    Route::put('/providers/{category}', [AdministratorOperationsController::class, 'saveProvider'])->middleware('permission:manage_provider_configuration');
    Route::post('/providers/{category}/rotate', fn (Request $request, string $category) => app(AdministratorOperationsController::class)->providerAction($request, $category, 'rotate'))->middleware('permission:rotate_provider_secrets');
    Route::post('/providers/{category}/disable', fn (Request $request, string $category) => app(AdministratorOperationsController::class)->providerAction($request, $category, 'disable'))->middleware('permission:manage_provider_configuration');
    Route::get('/providers/{category}/health', [AdministratorOperationsController::class, 'providerHealth'])->middleware('permission:view_provider_configuration');

    Route::post('/backups/preview', [AdministratorOperationsController::class, 'backupPreview'])->middleware('permission:create_backups');
    Route::post('/backups', [AdministratorOperationsController::class, 'createBackup'])->middleware('permission:create_backups');

    Route::get('/api-keys', [AdministratorOperationsController::class, 'apiKeys'])->middleware('permission:manage_api_keys');
    Route::post('/api-keys', [AdministratorOperationsController::class, 'createApiKey'])->middleware('permission:manage_api_keys');
    Route::get('/api-keys/{key}', [AdministratorOperationsController::class, 'apiKeys'])->middleware('permission:manage_api_keys');
    foreach (['rotate', 'revoke'] as $action) {
        Route::post('/api-keys/{key}/'.$action, fn (string $key) => app(AdministratorOperationsController::class)->apiKeyAction($key, $action))->middleware('permission:manage_api_keys');
    }
    Route::get('/webhooks', [AdministratorOperationsController::class, 'webhooks'])->middleware('permission:manage_webhooks');
    Route::post('/webhooks', [AdministratorOperationsController::class, 'saveWebhook'])->middleware('permission:manage_webhooks');
    Route::get('/webhooks/{webhook}', [AdministratorOperationsController::class, 'webhooks'])->middleware('permission:manage_webhooks');
    Route::put('/webhooks/{webhook}', [AdministratorOperationsController::class, 'saveWebhook'])->middleware('permission:manage_webhooks');
    Route::post('/webhooks/{webhook}/rotate-secret', fn (string $webhook) => app(AdministratorOperationsController::class)->webhookAction($webhook, 'rotate'))->middleware('permission:manage_webhooks');
    Route::post('/webhooks/{webhook}/disable', fn (string $webhook) => app(AdministratorOperationsController::class)->webhookAction($webhook, 'disable'))->middleware('permission:manage_webhooks');
    Route::get('/webhooks/{webhook}/deliveries', [AdministratorOperationsController::class, 'webhookDeliveries'])->middleware('permission:manage_webhooks');

    Route::get('/notices', [AdministratorOperationsController::class, 'notices'])->middleware('permission:manage_system_notices');
    Route::post('/notices', [AdministratorOperationsController::class, 'notices'])->middleware('permission:manage_system_notices');
    Route::put('/notices/{notice}', [AdministratorOperationsController::class, 'notices'])->middleware('permission:manage_system_notices');
    foreach (['publish', 'archive'] as $action) {
        Route::post('/notices/{notice}/'.$action, fn (Request $request, string $notice) => app(AdministratorOperationsController::class)->notices($request, $notice, $action))->middleware('permission:manage_system_notices');
    }

});

Route::prefix('communications')->middleware($secure)->group(function () {
    Route::post('/preview', [CommunicationController::class, 'previewDefinition'])->middleware('permission:preview_communication_recipients');
    Route::get('/', [CommunicationController::class, 'index'])->middleware('permission:view_communication_history');
    Route::post('/', [CommunicationController::class, 'store'])->middleware('permission:create_communications');
    Route::get('/analytics', [CommunicationController::class, 'analytics'])->middleware('permission:view_communication_analytics');
    Route::get('/recurring', [CommunicationPhaseTwoController::class, 'recurringIndex'])->middleware('permission:manage_recurring_communications');
    Route::post('/recurring', [CommunicationPhaseTwoController::class, 'recurringStore'])->middleware('permission:manage_recurring_communications');
    Route::put('/recurring/{schedule}', [CommunicationPhaseTwoController::class, 'recurringUpdate'])->middleware('permission:manage_recurring_communications');
    foreach (['pause', 'resume', 'cancel'] as $action) {
        Route::post('/recurring/{schedule}/'.$action, fn (string $schedule) => app(CommunicationPhaseTwoController::class)->recurringAction($schedule, $action))->middleware('permission:manage_recurring_communications');
    }
    Route::post('/emergency/preview', [CommunicationPhaseTwoController::class, 'emergencyPreview'])->middleware(['permission:send_emergency_broadcasts', 'throttle:5,1']);
    Route::post('/emergency/send', [CommunicationPhaseTwoController::class, 'emergencySend'])->middleware(['permission:send_emergency_broadcasts', 'throttle:3,1']);
    Route::get('/{communication}', [CommunicationController::class, 'show'])->middleware('permission:view_communication_history');
    Route::put('/{communication}', [CommunicationController::class, 'update'])->middleware('permission:edit_own_communication_drafts');
    Route::post('/{communication}/preview', [CommunicationController::class, 'preview'])->middleware('permission:preview_communication_recipients');
    Route::post('/{communication}/submit', fn (Request $request, string $communication) => app(CommunicationController::class)->action($request, $communication, 'submit'))->middleware('permission:create_communications');
    Route::post('/{communication}/approve', fn (Request $request, string $communication) => app(CommunicationController::class)->action($request, $communication, 'approve'))->middleware('permission:approve_communications');
    Route::post('/{communication}/reject', fn (Request $request, string $communication) => app(CommunicationController::class)->action($request, $communication, 'reject'))->middleware('permission:approve_communications');
    Route::post('/{communication}/send', fn (Request $request, string $communication) => app(CommunicationController::class)->action($request, $communication, 'send'))->middleware('permission:create_communications');
    Route::post('/{communication}/schedule', fn (Request $request, string $communication) => app(CommunicationController::class)->action($request, $communication, 'schedule'))->middleware('permission:schedule_communications');
    Route::post('/{communication}/cancel', fn (Request $request, string $communication) => app(CommunicationController::class)->action($request, $communication, 'cancel'))->middleware('permission:cancel_communications');
    Route::get('/{communication}/deliveries', [CommunicationController::class, 'deliveries'])->middleware('permission:view_communication_history');
    Route::get('/{communication}/audit', [CommunicationController::class, 'audit'])->middleware('permission:view_communication_history');
});

Route::prefix('communication')->middleware($secure)->group(function () {
    Route::get('/provider-health', [CommunicationPhaseTwoController::class, 'providerHealth'])->middleware('permission:view_provider_delivery_diagnostics');
    Route::get('/contact-health', [CommunicationPhaseTwoController::class, 'contactHealth'])->middleware('permission:manage_contact_health');
    Route::post('/contact-health/{contact}/restore', [CommunicationPhaseTwoController::class, 'restoreContact'])->middleware('permission:manage_email_suppressions');
    Route::get('/sms-wallet', [CommunicationPhaseTwoController::class, 'wallet'])->middleware('permission:view_sms_billing');
    Route::get('/sms-wallet/transactions', [CommunicationPhaseTwoController::class, 'walletTransactions'])->middleware('permission:view_sms_billing');
    Route::post('/sms-wallet/adjustments', [CommunicationPhaseTwoController::class, 'adjustWallet'])->middleware('permission:adjust_sms_credits');
    Route::get('/preferences', [CommunicationPhaseTwoController::class, 'preferences'])->middleware('permission:view_own_notifications');
    Route::put('/preferences', [CommunicationPhaseTwoController::class, 'updatePreferences'])->middleware('permission:view_own_notifications');
    Route::get('/branding', [CommunicationPhaseTwoController::class, 'branding'])->middleware('permission:manage_communication_branding');
    Route::put('/branding', [CommunicationPhaseTwoController::class, 'updateBranding'])->middleware('permission:manage_communication_branding');
    Route::get('/advanced-analytics', [CommunicationPhaseTwoController::class, 'analytics'])->middleware('permission:view_advanced_communication_analytics');
});

Route::prefix('webhooks/communication')->middleware('throttle:30,1')->group(function () {
    Route::post('/email/resend', [CommunicationWebhookController::class, 'resend']);
    Route::post('/sms/africas-talking', [CommunicationWebhookController::class, 'africasTalking']);
});

Route::post('webhooks/payments/mpesa', [ParentPaymentWebhookController::class, 'mpesa'])->middleware('throttle:30,1');

Route::prefix('communication-templates')->middleware($secure)->group(function () {
    Route::get('/', [CommunicationController::class, 'templates'])->middleware('permission:manage_communication_templates');
    Route::post('/', [CommunicationController::class, 'saveTemplate'])->middleware('permission:manage_communication_templates');
    Route::get('/{template}', [CommunicationController::class, 'template'])->middleware('permission:manage_communication_templates');
    Route::put('/{template}', [CommunicationController::class, 'saveTemplate'])->middleware('permission:manage_communication_templates');
    Route::post('/{template}/preview', [CommunicationController::class, 'previewTemplate'])->middleware('permission:manage_communication_templates');
    Route::post('/{template}/archive', [CommunicationController::class, 'archiveTemplate'])->middleware('permission:manage_communication_templates');
});

Route::prefix('communication-policies')->middleware($secure)->group(function () {
    Route::get('/', [CommunicationController::class, 'policies'])->middleware('permission:manage_communication_policies');
    Route::put('/{category}', [CommunicationController::class, 'updatePolicy'])->middleware('permission:manage_communication_policies');
});

Route::prefix('notifications')->middleware($secure)->group(function () {
    Route::get('/', [CommunicationController::class, 'notifications'])->middleware('permission:view_own_notifications');
    Route::get('/unread-count', [CommunicationController::class, 'unreadCount'])->middleware('permission:view_own_notifications');
    foreach (['read', 'unread', 'archive', 'dismiss'] as $state) {
        Route::post('/{notification}/'.$state, fn (string $notification) => app(CommunicationController::class)->notificationState($notification, $state === 'archive' ? 'archived' : ($state === 'dismiss' ? 'dismissed' : $state)))->middleware('permission:view_own_notifications');
    }
});

Route::prefix('announcements')->middleware($secure)->group(function () {
    Route::get('/', [CommunicationController::class, 'manageAnnouncements'])->middleware('permission:create_communications');
    Route::post('/', [CommunicationController::class, 'storeAnnouncement'])->middleware('permission:create_communications');
    Route::get('/current', [CommunicationController::class, 'announcements'])->middleware('permission:view_own_notifications');
    Route::get('/{announcement}', [CommunicationController::class, 'show'])->middleware('permission:view_communication_history');
    Route::put('/{announcement}', [CommunicationController::class, 'updateAnnouncement'])->middleware('permission:edit_own_communication_drafts');
    Route::post('/{announcement}/submit', fn (Request $request, string $announcement) => app(CommunicationController::class)->action($request, $announcement, 'submit'))->middleware('permission:create_communications');
    Route::post('/{announcement}/approve', fn (Request $request, string $announcement) => app(CommunicationController::class)->action($request, $announcement, 'approve'))->middleware('permission:approve_communications');
    Route::post('/{announcement}/publish', fn (Request $request, string $announcement) => app(CommunicationController::class)->action($request, $announcement, 'publish'))->middleware('permission:create_communications');
    Route::post('/{announcement}/schedule', fn (Request $request, string $announcement) => app(CommunicationController::class)->action($request, $announcement, 'schedule'))->middleware('permission:schedule_communications');
    Route::post('/{announcement}/cancel', fn (Request $request, string $announcement) => app(CommunicationController::class)->action($request, $announcement, 'cancel'))->middleware('permission:cancel_communications');
    Route::post('/{announcement}/archive', fn (Request $request, string $announcement) => app(CommunicationController::class)->action($request, $announcement, 'archive'))->middleware('permission:cancel_communications');
    Route::post('/{announcement}/read', [CommunicationController::class, 'announcementRead'])->middleware('permission:view_own_notifications');
});

Route::prefix('boarding')
    ->middleware($secure)
    ->group(function () {
        Route::get(
            '/hostels',
            [HostelController::class, 'index']
        )->middleware('permission:manage_boarding');

        Route::post(
            '/hostels',
            [HostelController::class, 'store']
        )->middleware([
            'permission:manage_boarding',
            'school.operational',
        ]);

        Route::get(
            '/hostels/{hostel}',
            [HostelController::class, 'show']
        )->middleware('permission:manage_boarding');

        Route::patch(
            '/hostels/{hostel}',
            [HostelController::class, 'update']
        )->middleware([
            'permission:manage_boarding',
            'school.operational',
        ]);

        Route::delete(
            '/hostels/{hostel}',
            [HostelController::class, 'destroy']
        )->middleware([
            'permission:manage_boarding',
            'school.operational',
        ]);

        Route::get(
            '/hostels/{hostel}/rooms',
            [HostelRoomController::class, 'index']
        )->middleware('permission:manage_boarding');

        Route::post(
            '/hostels/{hostel}/rooms',
            [HostelRoomController::class, 'store']
        )->middleware([
            'permission:manage_boarding',
            'school.operational',
        ]);

        Route::get(
            '/rooms/{room}',
            [HostelRoomController::class, 'show']
        )->middleware('permission:manage_boarding');

        Route::patch(
            '/rooms/{room}',
            [HostelRoomController::class, 'update']
        )->middleware([
            'permission:manage_boarding',
            'school.operational',
        ]);

        Route::delete(
            '/rooms/{room}',
            [HostelRoomController::class, 'destroy']
        )->middleware([
            'permission:manage_boarding',
            'school.operational',
        ]);

        Route::get(
            '/rooms/{room}/beds',
            [HostelBedController::class, 'index']
        )->middleware('permission:manage_boarding');

        Route::post(
            '/rooms/{room}/beds',
            [HostelBedController::class, 'store']
        )->middleware([
            'permission:manage_boarding',
            'school.operational',
        ]);

        Route::get(
            '/beds/{bed}',
            [HostelBedController::class, 'show']
        )->middleware('permission:manage_boarding');

        Route::patch(
            '/beds/{bed}',
            [HostelBedController::class, 'update']
        )->middleware([
            'permission:manage_boarding',
            'school.operational',
        ]);

        Route::delete(
            '/beds/{bed}',
            [HostelBedController::class, 'destroy']
        )->middleware([
            'permission:manage_boarding',
            'school.operational',
        ]);
    });
Route::prefix('academic-years')->middleware($secure)->group(function () {
    Route::get('/', [AcademicYearController::class, 'index']);
    Route::get('/{id}', [AcademicYearController::class, 'show']);
    Route::post('/', [AcademicYearController::class, 'store']);
    Route::put('/{id}', [AcademicYearController::class, 'update']);
    Route::delete('/{id}', [AcademicYearController::class, 'destroy']);
});

Route::prefix('terms')->middleware($secure)->group(function () {
    Route::get('/', [TermController::class, 'index']);
    Route::get('/{id}', [TermController::class, 'show']);
    Route::post('/', [TermController::class, 'store']);
    Route::put('/{id}', [TermController::class, 'update']);
    Route::delete('/{id}', [TermController::class, 'destroy']);
});
/*
|--------------------------------------------------------------------------
| Authentication Routes
|--------------------------------------------------------------------------
*/

Route::prefix('auth')->group(function () {

    Route::post('/login', [AuthController::class, 'login'])
        ->middleware('throttle:5,1');

    Route::middleware('jwt')->group(function () {

        Route::get('/me', [AuthController::class, 'me']);

        Route::post('/logout', [AuthController::class, 'logout']);

        Route::post('/refresh', [AuthController::class, 'refresh']);

    });

});

/*
|--------------------------------------------------------------------------
| School Routes
|--------------------------------------------------------------------------
*/

Route::prefix('schools')
    ->middleware($authenticated)
    ->group(function () {
        Route::get(
            '/',
            [SchoolController::class, 'index']
        )->middleware(
            'permission:access_platform_administration'
        );

        Route::get(
            '/{id}',
            [SchoolController::class, 'show']
        )->middleware(
            'permission:access_platform_administration'
        );
    });

/*
|--------------------------------------------------------------------------
| User Routes
|--------------------------------------------------------------------------
*/

Route::prefix('users')
    ->middleware($secure)
    ->group(function () {

        Route::get('/', [UserController::class, 'index'])->middleware('permission:view_school_users');

        Route::get('/{id}', [UserController::class, 'show'])->middleware('permission:view_school_users');

    });

/*
|--------------------------------------------------------------------------
| Teacher Routes
|--------------------------------------------------------------------------
*/

Route::prefix('teachers')
    ->middleware($secure)
    ->group(function () {

        Route::get('/', [TeacherController::class, 'index']);

        Route::get('/{id}', [TeacherController::class, 'show']);

        Route::post('/', [TeacherController::class, 'store']);

        Route::put('/{id}', [TeacherController::class, 'update']);

        Route::delete('/{id}', [TeacherController::class, 'destroy']);

    });

Route::prefix('teacher')->middleware($secure)->group(function () {
    Route::get('/tasks', [TeacherPortalPhaseTwoController::class, 'tasks'])->middleware('permission:access_teacher_portal');
    Route::get('/behaviour/categories', [BehaviourTeacherController::class, 'categories'])->middleware('permission:view_assigned_learner_behaviour');
    Route::get('/behaviour/learners/{learner}', [BehaviourTeacherController::class, 'learner'])->middleware('permission:view_assigned_learner_behaviour');
    Route::post('/behaviour/cases', [BehaviourTeacherController::class, 'store'])->middleware('permission:report_behaviour_cases');
    Route::get('/behaviour/cases', [BehaviourTeacherController::class, 'cases'])->middleware('permission:view_assigned_learner_behaviour');
    Route::get('/behaviour/cases/{case}', [BehaviourTeacherController::class, 'show'])->middleware('permission:view_assigned_learner_behaviour');
    Route::post('/behaviour/cases/{case}/actions', [BehaviourTeacherController::class, 'action'])->middleware('permission:assign_basic_behaviour_actions');
    Route::post('/behaviour/recognitions', [BehaviourTeacherController::class, 'recognize'])->middleware('permission:award_behaviour_recognition');
    Route::get('/behaviour/recognitions', [BehaviourTeacherController::class, 'recognitions'])->middleware('permission:view_assigned_learner_behaviour');
    Route::middleware('permission:manage_authorized_attendance')->group(function () {
        Route::get('/attendance/sessions', [AttendanceTeacherController::class, 'sessions'])->middleware('permission:manage_authorized_attendance');
        Route::get('/attendance/registers', [AttendanceTeacherController::class, 'index'])->middleware('permission:manage_authorized_attendance');
        Route::post('/attendance/registers', [AttendanceTeacherController::class, 'store'])->middleware(['permission:manage_authorized_attendance', TeacherAcademicScope::class]);
        Route::get('/attendance/registers/{register}', [AttendanceTeacherController::class, 'show'])->middleware('permission:manage_authorized_attendance');
        Route::put('/attendance/registers/{register}', [AttendanceTeacherController::class, 'draft'])->middleware('permission:manage_authorized_attendance');
        Route::post('/attendance/registers/{register}/records', [AttendanceTeacherController::class, 'draft'])->middleware('permission:manage_authorized_attendance');
        Route::put('/attendance/registers/{register}/records/{record}', [AttendanceTeacherController::class, 'updateRecord'])->middleware('permission:manage_authorized_attendance');
        Route::put('/attendance/registers/{register}/draft', [AttendanceTeacherController::class, 'draft'])->middleware('permission:manage_authorized_attendance');
        Route::post('/attendance/registers/{register}/submit', [AttendanceTeacherController::class, 'finalize'])->middleware('permission:manage_authorized_attendance');
        Route::post('/attendance/registers/{register}/finalize', [AttendanceTeacherController::class, 'finalize'])->middleware('permission:manage_authorized_attendance');
        Route::post('/attendance/registers/{register}/reopen', [AttendanceTeacherController::class, 'reopen'])->middleware('permission:manage_authorized_attendance');
        Route::post('/attendance/registers/{register}/cancel', [AttendanceTeacherController::class, 'cancel'])->middleware('permission:manage_authorized_attendance');
        Route::get('/attendance/registers/{register}/learners', [AttendanceTeacherController::class, 'show']);
    });
    Route::middleware('permission:manage_assigned_homework')->group(function () {
        Route::get('/homework', [HomeworkTeacherController::class, 'index'])->middleware('permission:manage_assigned_homework');
        Route::post('/homework', [HomeworkTeacherController::class, 'store'])->middleware(['permission:manage_assigned_homework', TeacherAcademicScope::class]);
        Route::get('/homework/{assignment}', [HomeworkTeacherController::class, 'show'])->middleware('permission:manage_assigned_homework');
        Route::put('/homework/{assignment}', [HomeworkTeacherController::class, 'update'])->middleware(['permission:manage_assigned_homework', TeacherAcademicScope::class]);
        Route::post('/homework/{assignment}/resources', [HomeworkTeacherController::class, 'resource']);
        Route::get('/homework/{assignment}/rubric', [HomeworkTeacherController::class, 'rubric']);
        Route::post('/homework/{assignment}/rubric', [HomeworkTeacherController::class, 'rubric']);
        Route::put('/homework/{assignment}/rubric', [HomeworkTeacherController::class, 'rubric']);
        foreach (['scheduled' => 'schedule', 'published' => 'publish', 'closed' => 'close', 'cancelled' => 'cancel', 'archived' => 'archive'] as $status => $path) {
            Route::post('/homework/{assignment}/'.$path, fn (string $assignment) => app(HomeworkTeacherController::class)->transition($assignment, $status));
        }
        Route::get('/homework/{assignment}/learners', [HomeworkTeacherController::class, 'learners']);
        Route::get('/homework/{assignment}/submissions', [HomeworkTeacherController::class, 'submissions']);
        Route::get('/homework/{assignment}/submissions/{submission}', [HomeworkTeacherController::class, 'submission']);
        Route::get('/homework/{assignment}/submissions/{submission}/files/{file}/download', [HomeworkTeacherController::class, 'download']);
        Route::post('/homework/{assignment}/submissions/{submission}/return', fn (Request $r, string $assignment, string $submission) => app(HomeworkTeacherController::class)->returnSubmission($r, $assignment, $submission));
        Route::post('/homework/{assignment}/submissions/{submission}/request-resubmission', fn (Request $r, string $assignment, string $submission) => app(HomeworkTeacherController::class)->returnSubmission($r, $assignment, $submission, true));
        Route::put('/homework/{assignment}/submissions/{submission}/mark', [HomeworkTeacherController::class, 'mark']);
        Route::post('/homework/{assignment}/submissions/{submission}/feedback', [HomeworkTeacherController::class, 'mark']);
        Route::post('/homework/{assignment}/submissions/{submission}/release', [HomeworkTeacherController::class, 'release']);
    });
    Route::middleware('permission:manage_assigned_learning_resources')->group(function () {
        Route::get('/resources', [LearningResourceTeacherController::class, 'index'])->middleware('permission:manage_assigned_learning_resources');
        Route::get('/resources/{resource}', [LearningResourceTeacherController::class, 'show']);
        Route::post('/resources', [LearningResourceTeacherController::class, 'create']);
        Route::post('/resources/upload', [LearningResourceTeacherController::class, 'upload']);
        Route::put('/resources/{resource}', [LearningResourceTeacherController::class, 'update']);
        Route::post('/resources/{resource}/submit', [LearningResourceTeacherController::class, 'submit']);
        Route::post('/resources/{resource}/archive', [LearningResourceTeacherController::class, 'archive']);
        Route::get('/resources/{resource}/download', [LearningResourceTeacherController::class, 'download']);
        Route::get('/resources/{resource}/versions', [LearningResourceTeacherController::class, 'versions']);
        Route::get('/resources/{resource}/versions/{version}/download', [LearningResourceTeacherController::class, 'downloadVersion']);
        Route::post('/resources/{resource}/versions/upload', [LearningResourceTeacherController::class, 'uploadVersion']);
        Route::post('/resources/{resource}/versions/link', [LearningResourceTeacherController::class, 'linkVersion']);
        Route::post('/resources/{resource}/versions/{version}/restore', [LearningResourceTeacherController::class, 'restore']);
    });
    Route::get('/dashboard', [TeacherPortalMobileController::class, 'dashboard'])->middleware('permission:access_teacher_portal');
    Route::get('/profile', [TeacherPortalMobileController::class, 'profile'])->middleware('permission:view_own_teacher_profile');
    Route::put('/profile', [TeacherPortalMobileController::class, 'updateProfile'])->middleware('permission:update_own_teacher_profile');
    Route::get('/assignments', [TeacherPortalMobileController::class, 'assignments'])->middleware('permission:view_own_teacher_assignments');
    Route::get('/assignments/{assignment}/learners', [TeacherPortalMobileController::class, 'assignmentLearners'])->middleware(['permission:view_assigned_learners', TeacherAcademicScope::class]);
    Route::get('/assignments/{assignment}', [TeacherPortalMobileController::class, 'assignment'])->middleware(['permission:view_own_teacher_assignments', TeacherAcademicScope::class]);
    Route::get('/classes/{stream}/learners', [TeacherPortalMobileController::class, 'classLearners'])->middleware(['permission:view_assigned_learners', TeacherAcademicScope::class]);
    Route::get('/classes', [TeacherPortalMobileController::class, 'classes'])->middleware('permission:view_own_teacher_assignments');
    Route::get('/class-teacher/learners', [TeacherPortalMobileController::class, 'classTeacherLearners'])->middleware('permission:use_class_teacher_tools');
    Route::get('/me', [TeacherPortalMobileController::class, 'profile'])->middleware('permission:view_own_teacher_profile');
    Route::get('/learners', [TeacherPortalMobileController::class, 'classTeacherLearners'])->middleware('permission:view_assigned_learners');
    Route::get('/timetable', [TeacherPortalMobileController::class, 'timetable'])->middleware('permission:view_own_timetable');
    Route::get('/timetable/today', [TeacherPortalMobileController::class, 'timetableToday'])->middleware('permission:view_own_timetable');
    Route::get('/timetable/week', [TeacherPortalMobileController::class, 'timetable'])->middleware('permission:view_own_timetable');
    Route::get('/timetable/current-period', [SmartTimetableController::class, 'currentPeriod'])->middleware('permission:view_own_timetable');
    Route::get('/schemes', [TeacherPortalMobileController::class, 'schemes'])->middleware('permission:view_own_schemes');
    Route::post('/schemes', [SchemeOfWorkController::class, 'store'])->middleware(['permission:manage_own_scheme_drafts', TeacherAcademicScope::class]);
    Route::get('/schemes/{scheme}/lessons/{lesson}', [TeacherPortalMobileController::class, 'schemeLessons'])->middleware(['permission:view_own_scheme_lessons', TeacherAcademicScope::class]);
    Route::get('/schemes/{scheme}/lessons', [TeacherPortalMobileController::class, 'schemeLessons'])->middleware(['permission:view_own_scheme_lessons', TeacherAcademicScope::class]);
    Route::post('/schemes/{scheme}/lessons', function (Request $request, string $scheme) {
        $request->merge(['scheme_id' => $scheme]);

        return app(SchemeLessonController::class)->store($request);
    })->middleware(['permission:manage_own_scheme_lessons', TeacherAcademicScope::class]);
    Route::put('/schemes/{scheme}/lessons/{lesson}', fn (Request $request, string $scheme, string $lesson) => app(SchemeLessonController::class)->update($request, $lesson))->middleware(['permission:manage_own_scheme_lessons', TeacherAcademicScope::class]);
    Route::get('/schemes/{scheme}', [TeacherPortalMobileController::class, 'schemes'])->middleware(['permission:view_own_schemes', TeacherAcademicScope::class]);
    Route::put('/schemes/{scheme}', [SchemeOfWorkController::class, 'update'])->middleware(['permission:manage_own_scheme_drafts', TeacherAcademicScope::class]);
    Route::post('/schemes/{scheme}/submit', fn (string $scheme) => app(TeacherPortalPhaseTwoController::class)->submit('scheme_of_work', $scheme))->middleware(['permission:submit_own_schemes', TeacherAcademicScope::class]);
    Route::post('/schemes/{scheme}/withdraw', fn (string $scheme) => app(TeacherPortalPhaseTwoController::class)->withdraw('scheme_of_work', $scheme))->middleware(['permission:withdraw_own_scheme_submission', TeacherAcademicScope::class]);
    Route::get('/schemes/{scheme}/workflow-history', fn (string $scheme) => app(TeacherPortalPhaseTwoController::class)->history('scheme_of_work', $scheme))->middleware(['permission:view_own_workflow_history', TeacherAcademicScope::class]);
    Route::get('/lesson-plans/{lessonPlan}', [TeacherPortalMobileController::class, 'lessonPlans'])->middleware(['permission:view_own_lesson_plans', TeacherAcademicScope::class]);
    Route::get('/lesson-plans', [TeacherPortalMobileController::class, 'lessonPlans'])->middleware('permission:view_own_lesson_plans');
    Route::post('/lesson-plans', [LessonPlanController::class, 'store'])->middleware(['permission:manage_own_lesson_plan_drafts', TeacherAcademicScope::class]);
    Route::put('/lesson-plans/{lessonPlan}', [LessonPlanController::class, 'update'])->middleware(['permission:manage_own_lesson_plan_drafts', TeacherAcademicScope::class]);
    Route::post('/lesson-plans/{lessonPlan}/submit', fn (string $lessonPlan) => app(TeacherPortalPhaseTwoController::class)->submit('lesson_plan', $lessonPlan))->middleware(['permission:submit_own_lesson_plans', TeacherAcademicScope::class]);
    Route::post('/lesson-plans/{lessonPlan}/withdraw', fn (string $lessonPlan) => app(TeacherPortalPhaseTwoController::class)->withdraw('lesson_plan', $lessonPlan))->middleware(['permission:withdraw_own_lesson_plan_submission', TeacherAcademicScope::class]);
    Route::get('/lesson-plans/{lessonPlan}/workflow-history', fn (string $lessonPlan) => app(TeacherPortalPhaseTwoController::class)->history('lesson_plan', $lessonPlan))->middleware(['permission:view_own_workflow_history', TeacherAcademicScope::class]);
    Route::get('/lesson-notes/{lessonNote}', [TeacherPortalMobileController::class, 'lessonNotes'])->middleware(['permission:view_own_lesson_notes', TeacherAcademicScope::class]);
    Route::get('/lesson-notes', [TeacherPortalMobileController::class, 'lessonNotes'])->middleware('permission:view_own_lesson_notes');
    Route::post('/lesson-notes', [LessonNoteController::class, 'store'])->middleware(['permission:manage_own_lesson_note_drafts', TeacherAcademicScope::class]);
    Route::put('/lesson-notes/{lessonNote}', [LessonNoteController::class, 'update'])->middleware(['permission:manage_own_lesson_note_drafts', TeacherAcademicScope::class]);
    Route::post('/lesson-notes/{lessonNote}/submit', fn (string $lessonNote) => app(TeacherPortalPhaseTwoController::class)->submit('lesson_note', $lessonNote))->middleware(['permission:submit_own_lesson_notes', TeacherAcademicScope::class]);
    Route::post('/lesson-notes/{lessonNote}/withdraw', fn (string $lessonNote) => app(TeacherPortalPhaseTwoController::class)->withdraw('lesson_note', $lessonNote))->middleware(['permission:withdraw_own_lesson_note_submission', TeacherAcademicScope::class]);
    Route::get('/lesson-notes/{lessonNote}/workflow-history', fn (string $lessonNote) => app(TeacherPortalPhaseTwoController::class)->history('lesson_note', $lessonNote))->middleware(['permission:view_own_workflow_history', TeacherAcademicScope::class]);
    Route::get('/records-of-work/compliance', fn () => app(TeacherPortalPhaseTwoController::class)->compliance())->middleware('permission:view_own_records_of_work');
    Route::get('/records-of-work/{record}', [TeacherPortalMobileController::class, 'records'])->middleware(['permission:view_own_records_of_work', TeacherAcademicScope::class]);
    Route::get('/records-of-work', [TeacherPortalMobileController::class, 'records'])->middleware('permission:view_own_records_of_work');
    Route::post('/records-of-work', [RecordOfWorkController::class, 'store'])->middleware(['permission:manage_own_records_of_work', TeacherAcademicScope::class]);
    Route::put('/records-of-work/{record}', [RecordOfWorkController::class, 'update'])->middleware(['permission:manage_own_records_of_work', TeacherAcademicScope::class]);
    Route::post('/records-of-work/{record}/submit', fn (string $record) => app(TeacherPortalPhaseTwoController::class)->submit('record_of_work', $record))->middleware(['permission:submit_own_records_of_work', TeacherAcademicScope::class]);
    Route::post('/records-of-work/{record}/withdraw', fn (string $record) => app(TeacherPortalPhaseTwoController::class)->withdraw('record_of_work', $record))->middleware(['permission:withdraw_own_record_of_work_submission', TeacherAcademicScope::class]);
    Route::get('/records-of-work/{record}/workflow-history', fn (string $record) => app(TeacherPortalPhaseTwoController::class)->history('record_of_work', $record))->middleware(['permission:view_own_workflow_history', TeacherAcademicScope::class]);
    Route::get('/curriculum-coverage/{coverage}', [TeacherPortalMobileController::class, 'coverage'])->middleware(['permission:view_own_curriculum_coverage', TeacherAcademicScope::class]);
    Route::get('/curriculum-coverage', [TeacherPortalMobileController::class, 'coverage'])->middleware('permission:view_own_curriculum_coverage');
    Route::post('/curriculum-coverage/recalculate', [CurriculumCoverageController::class, 'store'])->middleware(['permission:view_own_curriculum_coverage', TeacherAcademicScope::class]);
    Route::get('/assessments', [TeacherPortalMobileController::class, 'assessments'])->middleware('permission:view_assigned_assessments');
    Route::get('/assessments/{exam}', [TeacherPortalMobileController::class, 'assessment'])->middleware('permission:view_assigned_assessments');
    Route::get('/assessments/{exam}/papers/{paper}/learners', [TeacherPortalMobileController::class, 'paperLearners'])->middleware('permission:view_assigned_assessments');
    Route::get('/assessments/{exam}/papers', [TeacherPortalMobileController::class, 'assessmentPapers'])->middleware('permission:view_assigned_assessments');
    Route::get('/marks-entry/tasks', [TeacherPortalMobileController::class, 'assessments'])->middleware('permission:enter_authorized_marks');
    Route::get('/marks-entry/batches', [TeacherPortalPhaseTwoController::class, 'batches'])->middleware('permission:submit_mark_entry_batches');
    Route::get('/marks-entry/batches/{batch}', [TeacherPortalPhaseTwoController::class, 'batches'])->middleware('permission:submit_mark_entry_batches');
    Route::post('/marks-entry/batches/{batch}/submit', [TeacherPortalPhaseTwoController::class, 'submitBatch'])->middleware('permission:submit_mark_entry_batches');
    Route::post('/marks-entry/batches/{batch}/resubmit', [TeacherPortalPhaseTwoController::class, 'submitBatch'])->middleware('permission:submit_mark_entry_batches');
    Route::post('/marks-entry/batches/{batch}/correction-request', [TeacherPortalPhaseTwoController::class, 'correction'])->middleware('permission:request_mark_corrections');
    Route::get('/marks-entry/correction-requests', [TeacherPortalPhaseTwoController::class, 'correctionRequests'])->middleware('permission:request_mark_corrections');
    Route::post('/marks-entry/correction-requests/{correction}/approve', fn (Request $request, string $correction) => app(TeacherPortalPhaseTwoController::class)->decideCorrection($request, $correction, 'approved'))->middleware('permission:approve_mark_corrections');
    Route::post('/marks-entry/correction-requests/{correction}/reject', fn (Request $request, string $correction) => app(TeacherPortalPhaseTwoController::class)->decideCorrection($request, $correction, 'rejected'))->middleware('permission:approve_mark_corrections');
    Route::get('/marks-entry/{paper}', [TeacherPortalMobileController::class, 'marksEntry'])->middleware('permission:enter_authorized_marks');
    Route::post('/marks-entry/{paper}', [TeacherPortalPhaseTwoController::class, 'saveMarks'])->middleware('permission:enter_authorized_marks');
    Route::post('/marks-entry/{paper}/submit', [TeacherPortalMobileController::class, 'submitMarks'])->middleware('permission:submit_authorized_marks');
    Route::get('/moderation/marks', [TeacherPortalPhaseTwoController::class, 'moderation'])->middleware('permission:moderate_mark_entry_batches');
    Route::get('/moderation/marks/{batch}', [TeacherPortalPhaseTwoController::class, 'moderation'])->middleware('permission:moderate_mark_entry_batches');
    foreach (['approved' => 'approve', 'changes_requested' => 'request-changes', 'rejected' => 'reject', 'locked' => 'lock'] as $state => $path) {
        Route::post('/moderation/marks/{batch}/'.$path, fn (Request $request, string $batch) => app(TeacherPortalPhaseTwoController::class)->moderate($request, $batch, $state))->middleware($state === 'locked' ? 'permission:lock_moderated_mark_batches' : 'permission:moderate_mark_entry_batches');
    }
    Route::get('/hod/dashboard', [TeacherPortalPhaseTwoController::class, 'hodDashboard'])->middleware('permission:review_department_teaching_work');
    Route::get('/hod/review-queue/{workflow}', [TeacherPortalPhaseTwoController::class, 'reviewQueue'])->middleware('permission:review_department_teaching_work');
    Route::get('/hod/review-queue', [TeacherPortalPhaseTwoController::class, 'reviewQueue'])->middleware('permission:review_department_teaching_work');
    foreach (['approved' => ['approve', 'approve_department_teaching_work'], 'changes_requested' => ['request-changes', 'request_department_work_changes'], 'rejected' => ['reject', 'reject_department_teaching_work']] as $state => [$path, $permission]) {
        Route::post('/hod/review-queue/{workflow}/'.$path, fn (Request $request, string $workflow) => app(TeacherPortalPhaseTwoController::class)->review($request, $workflow, $state))->middleware('permission:'.$permission);
    }
    Route::get('/hod/teachers', [TeacherPortalPhaseTwoController::class, 'hodTeachers'])->middleware('permission:view_department_compliance');
    Route::get('/hod/compliance', fn () => app(TeacherPortalPhaseTwoController::class)->compliance(true))->middleware('permission:view_department_compliance');
    Route::get('/hod/curriculum-coverage', [TeacherPortalPhaseTwoController::class, 'hodCoverage'])->middleware('permission:view_department_compliance');
    Route::get('/hod/analytics', fn () => app(TeacherPortalPhaseTwoController::class)->compliance(true))->middleware('permission:view_hod_analytics');
    Route::post('/sync/push', [TeacherPortalPhaseTwoController::class, 'syncPush'])->middleware('permission:use_teacher_offline_sync');
    Route::get('/sync/pull', [TeacherPortalPhaseTwoController::class, 'syncPull'])->middleware('permission:use_teacher_offline_sync');
    Route::get('/sync/status', [TeacherPortalPhaseTwoController::class, 'syncStatus'])->middleware('permission:use_teacher_offline_sync');
    Route::get('/sync/conflicts', [TeacherPortalPhaseTwoController::class, 'syncConflicts'])->middleware('permission:use_teacher_offline_sync');
    Route::post('/sync/conflicts/{conflict}/resolve', [TeacherPortalPhaseTwoController::class, 'resolveConflict'])->middleware('permission:resolve_own_sync_conflicts');
    Route::post('/uploads', [TeacherPortalPhaseTwoController::class, 'upload'])->middleware('permission:upload_teacher_portal_files');
    Route::get('/uploads/{attachment}/download', [TeacherPortalPhaseTwoController::class, 'download'])->middleware('permission:download_teacher_portal_files');
    Route::get('/uploads/{attachment}', [TeacherPortalPhaseTwoController::class, 'attachment'])->middleware('permission:download_teacher_portal_files');
    Route::delete('/uploads/{attachment}', [TeacherPortalPhaseTwoController::class, 'archiveAttachment'])->middleware('permission:upload_teacher_portal_files');
    Route::get('/push/deliveries', [TeacherPortalPhaseTwoController::class, 'pushDeliveries'])->middleware('permission:view_own_push_delivery_status');
    Route::post('/devices/{device}/push-token', [TeacherPortalPhaseTwoController::class, 'rotatePushToken'])->middleware('permission:manage_own_push_devices');
    Route::delete('/devices/{device}/push-token', [TeacherPortalPhaseTwoController::class, 'removePushToken'])->middleware('permission:manage_own_push_devices');
    Route::get('/class-teacher/dashboard', [TeacherPortalMobileController::class, 'classTeacherDashboard'])->middleware('permission:use_class_teacher_tools');
    Route::get('/class-teacher/attendance-summary', [TeacherPortalMobileController::class, 'analytics'])->middleware('permission:use_class_teacher_tools');
    Route::get('/class-teacher/communications', [TeacherPortalMobileController::class, 'communications'])->middleware('permission:use_class_teacher_tools');
    Route::post('/class-teacher/communications/preview', [TeacherPortalMobileController::class, 'communicationPreview'])->middleware('permission:use_class_teacher_tools');
    Route::post('/class-teacher/communications', [TeacherPortalMobileController::class, 'communicationCreate'])->middleware('permission:use_class_teacher_tools');
    Route::get('/learning-resources', [LearningResourceTeacherController::class, 'index'])->middleware('permission:manage_assigned_learning_resources');
    Route::post('/learning-resources', [LearningResourceTeacherController::class, 'create'])->middleware(['permission:manage_assigned_learning_resources', TeacherAcademicScope::class]);
    Route::get('/learning-resources/{resource}', [LearningResourceTeacherController::class, 'show'])->middleware('permission:manage_assigned_learning_resources');
    Route::put('/learning-resources/{resource}', [LearningResourceTeacherController::class, 'update'])->middleware('permission:manage_assigned_learning_resources');
    Route::post('/learning-resources/{resource}/publish', [LearningResourceTeacherController::class, 'submit'])->middleware('permission:manage_assigned_learning_resources');
    Route::post('/learning-resources/{resource}/archive', [LearningResourceTeacherController::class, 'archive'])->middleware('permission:manage_assigned_learning_resources');
    Route::get('/attendance', [TeacherPortalController::class, 'attendance'])->middleware('permission:manage_authorized_attendance');
    Route::get('/communications/{communication}', [TeacherPortalMobileController::class, 'communications'])->middleware('permission:view_teacher_communications');
    Route::get('/communications', [TeacherPortalMobileController::class, 'communications'])->middleware('permission:view_teacher_communications');
    Route::post('/communications/preview', [TeacherPortalMobileController::class, 'communicationPreview'])->middleware('permission:send_assigned_class_communications');
    Route::post('/communications', [TeacherPortalMobileController::class, 'communicationCreate'])->middleware('permission:send_assigned_class_communications');
    Route::post('/communications/{communication}/submit', [TeacherPortalMobileController::class, 'communicationSubmit'])->middleware('permission:send_assigned_class_communications');
    Route::post('/communications/{communication}/send', [TeacherPortalMobileController::class, 'communicationSend'])->middleware('permission:send_assigned_class_communications');
    Route::get('/notifications/unread-count', [TeacherPortalMobileController::class, 'unreadCount'])->middleware('permission:view_teacher_communications');
    Route::get('/notifications', [TeacherPortalMobileController::class, 'notifications'])->middleware('permission:view_teacher_communications');
    foreach (['read', 'unread', 'archive', 'dismiss'] as $state) {
        Route::post('/notifications/{notification}/'.$state, fn (string $notification) => app(TeacherPortalMobileController::class)->notificationState($notification, $state))->middleware('permission:view_teacher_communications');
    }
    Route::post('/announcements/{announcement}/read', [TeacherPortalMobileController::class, 'announcementRead'])->middleware('permission:view_teacher_announcements');
    Route::get('/announcements/{announcement}', [TeacherPortalMobileController::class, 'announcements'])->middleware('permission:view_teacher_announcements');
    Route::get('/announcements', [TeacherPortalMobileController::class, 'announcements'])->middleware('permission:view_teacher_announcements');
    Route::get('/calendar', fn (Request $request) => app(TeacherPortalMobileController::class)->calendar($request))->middleware('permission:view_teacher_calendar');
    Route::get('/calendar/upcoming', fn (Request $request) => app(TeacherPortalMobileController::class)->calendar($request, true))->middleware('permission:view_teacher_calendar');
    Route::get('/analytics', [TeacherPortalMobileController::class, 'analytics'])->middleware('permission:view_own_teacher_analytics');
    Route::get('/preferences', [TeacherPortalMobileController::class, 'preferences'])->middleware('permission:manage_own_teacher_preferences');
    Route::put('/preferences', [TeacherPortalMobileController::class, 'updatePreferences'])->middleware('permission:manage_own_teacher_preferences');
    Route::get('/devices', [TeacherPortalMobileController::class, 'devices'])->middleware('permission:manage_own_teacher_devices');
    Route::post('/devices', [TeacherPortalMobileController::class, 'registerDevice'])->middleware('permission:manage_own_teacher_devices');
    Route::delete('/devices/{device}', [TeacherPortalMobileController::class, 'revokeDevice'])->middleware('permission:manage_own_teacher_devices');
    Route::get('/dashboard-preferences', [TeacherPortalMobileController::class, 'preferences'])->middleware('permission:manage_own_teacher_preferences');
    Route::patch('/dashboard-preferences', [TeacherPortalMobileController::class, 'updatePreferences'])->middleware('permission:manage_own_teacher_preferences');
});

Route::prefix('leadership')->middleware($secure)->group(function () {
    Route::get('/me', [LeadershipPortalController::class, 'me']);
    Route::get('/dashboard', [LeadershipPortalPhaseTwoController::class, 'dashboard'])->middleware('permission:access_leadership_portal_phase_two');
    foreach (['principal', 'deputy', 'hod', 'director'] as $view) {
        Route::get('/dashboard/'.$view, fn () => app(LeadershipPortalPhaseTwoController::class)->dashboard($view))->middleware('permission:view_'.$view.'_dashboard');
    }
    Route::get('/dashboard-preferences', [LeadershipPortalController::class, 'preferences']);
    Route::patch('/dashboard-preferences', [LeadershipPortalController::class, 'updatePreferences']);
    Route::get('/attendance', [LeadershipPortalController::class, 'attendance']);
    Route::get('/curriculum-coverage', [LeadershipPortalController::class, 'curriculum']);
    Route::get('/approvals', [LeadershipPortalPhaseTwoController::class, 'approvals'])->middleware('permission:review_cross_module_approvals');
    Route::get('/approvals/summary', [LeadershipPortalPhaseTwoController::class, 'approvalSummary'])->middleware('permission:review_cross_module_approvals');
    Route::get('/approvals/{approval}', [LeadershipPortalPhaseTwoController::class, 'approval'])->middleware('permission:review_cross_module_approvals');
    Route::post('/approvals/{approval}/approve', fn (Request $request, string $approval) => app(LeadershipPortalPhaseTwoController::class)->decideApproval($request, $approval, 'approved'))->middleware('permission:review_cross_module_approvals');
    Route::post('/approvals/{approval}/request-changes', fn (Request $request, string $approval) => app(LeadershipPortalPhaseTwoController::class)->decideApproval($request, $approval, 'changes_requested'))->middleware('permission:review_cross_module_approvals');
    Route::post('/approvals/{approval}/reject', fn (Request $request, string $approval) => app(LeadershipPortalPhaseTwoController::class)->decideApproval($request, $approval, 'rejected'))->middleware('permission:review_cross_module_approvals');
    Route::get('/lesson-plans', [LeadershipPortalController::class, 'lessonPlans']);
    Route::get('/records-of-work', [LeadershipPortalController::class, 'records']);
    Route::get('/teacher-workload', [LeadershipPortalController::class, 'workload']);
    Route::get('/assessments', [LeadershipPortalController::class, 'assessments']);
    Route::get('/report-cards', [LeadershipPortalController::class, 'reports']);
    Route::get('/academic-performance', [LeadershipPortalController::class, 'academic']);
    Route::get('/discipline', [LeadershipPortalController::class, 'discipline']);
    Route::get('/finance', [LeadershipPortalController::class, 'finance']);
    Route::get('/announcements', [LeadershipPortalController::class, 'announcements']);
    Route::get('/notifications', [LeadershipPortalController::class, 'notifications']);

    Route::get('/teachers', [LeadershipPortalPhaseTwoController::class, 'teachers'])->middleware('permission:view_teacher_compliance');
    Route::get('/teachers/{teacher}', [LeadershipPortalPhaseTwoController::class, 'teacher'])->middleware('permission:view_teacher_compliance');
    foreach (['workload', 'compliance', 'teaching-workflows', 'attendance-submissions', 'homework-activity', 'marks-progress'] as $metric) {
        Route::get('/teachers/{teacher}/'.$metric, fn (string $teacher) => app(LeadershipPortalPhaseTwoController::class)->teacherMetric($teacher, $metric))->middleware('permission:'.($metric === 'workload' ? 'view_teacher_workload' : 'view_teacher_compliance'));
    }

    Route::get('/kpis', [LeadershipPortalPhaseTwoController::class, 'kpis'])->middleware('permission:view_school_kpis');
    Route::get('/kpis/trends', fn () => app(LeadershipPortalPhaseTwoController::class)->kpis(true))->middleware('permission:view_school_kpis');

    Route::get('/academics/summary', fn () => app(LeadershipPortalPhaseTwoController::class)->academic('summary'))->middleware('permission:view_academic_insights');
    Route::get('/academics/grades', fn () => app(LeadershipPortalPhaseTwoController::class)->academic('grades'))->middleware('permission:view_academic_insights');
    Route::get('/academics/grades/{grade}', fn (string $grade) => app(LeadershipPortalPhaseTwoController::class)->academic('grade', $grade))->middleware('permission:view_academic_insights');
    Route::get('/academics/streams/{stream}', fn (string $stream) => app(LeadershipPortalPhaseTwoController::class)->academic('stream', $stream))->middleware('permission:view_academic_insights');
    Route::get('/academics/learning-areas', fn () => app(LeadershipPortalPhaseTwoController::class)->academic('learning-areas'))->middleware('permission:view_academic_insights');
    Route::get('/academics/learning-areas/{learningArea}', fn (string $learningArea) => app(LeadershipPortalPhaseTwoController::class)->academic('learning-area', $learningArea))->middleware('permission:view_academic_insights');
    Route::get('/academics/readiness', fn () => app(LeadershipPortalPhaseTwoController::class)->academic('readiness'))->middleware('permission:view_academic_insights');
    Route::get('/academics/interventions', fn () => app(LeadershipPortalPhaseTwoController::class)->academic('interventions'))->middleware('permission:view_academic_insights');

    foreach (['summary', 'today', 'trends', 'alerts'] as $view) {
        Route::get('/attendance/'.$view, fn () => app(LeadershipPortalPhaseTwoController::class)->attendance($view))->middleware('permission:view_attendance_intelligence');
    }
    Route::get('/attendance/grades/{grade}', fn (string $grade) => app(LeadershipPortalPhaseTwoController::class)->attendance('grade', $grade))->middleware('permission:view_attendance_intelligence');
    Route::get('/attendance/streams/{stream}', fn (string $stream) => app(LeadershipPortalPhaseTwoController::class)->attendance('stream', $stream))->middleware('permission:view_attendance_intelligence');
    Route::get('/attendance/learners/{learner}', fn (string $learner) => app(LeadershipPortalPhaseTwoController::class)->attendance('learner', $learner))->middleware(['permission:view_attendance_intelligence', 'permission:view_attendance_analytics']);

    foreach (['summary', 'trends', 'escalations', 'recognitions'] as $view) {
        Route::get('/behaviour/'.$view, fn () => app(LeadershipPortalPhaseTwoController::class)->behaviour($view))->middleware('permission:view_behaviour_oversight');
    }
    Route::get('/behaviour/cases/{case}', fn (string $case) => app(LeadershipPortalPhaseTwoController::class)->behaviour('case', $case))->middleware('permission:view_behaviour_oversight');

    foreach (['summary', 'collections', 'outstanding', 'arrears', 'payment-plans', 'refunds', 'adjustments', 'sms-wallet'] as $view) {
        Route::get('/finance/'.$view, fn () => app(LeadershipPortalPhaseTwoController::class)->finance($view))->middleware('permission:view_finance_oversight');
    }
    foreach (['summary', 'pending-approvals', 'delivery-health', 'failures', 'emergencies', 'sms-usage'] as $view) {
        Route::get('/communications/'.$view, fn () => app(LeadershipPortalPhaseTwoController::class)->communications($view))->middleware('permission:view_communication_monitoring');
    }
    foreach (['summary', 'coverage', 'conflicts', 'substitutions', 'uncovered-lessons', 'teacher-workload'] as $view) {
        Route::get('/timetable/'.$view, fn () => app(LeadershipPortalPhaseTwoController::class)->timetable($view))->middleware('permission:view_timetable_oversight');
    }

    Route::get('/actions', [LeadershipPortalPhaseTwoController::class, 'actions'])->middleware('permission:view_leadership_action_queue');
    Route::get('/alerts', [LeadershipPortalPhaseTwoController::class, 'alerts'])->middleware('permission:view_leadership_alerts');
    Route::post('/alerts/{alert}/acknowledge', fn (string $alert) => app(LeadershipPortalPhaseTwoController::class)->alertState($alert, 'acknowledged'))->middleware('permission:acknowledge_leadership_alerts');
    Route::post('/alerts/{alert}/dismiss', fn (string $alert) => app(LeadershipPortalPhaseTwoController::class)->alertState($alert, 'dismissed'))->middleware('permission:acknowledge_leadership_alerts');

    foreach (['dashboard', 'teachers', 'compliance', 'curriculum-coverage', 'marks', 'homework', 'resources', 'communications'] as $view) {
        Route::get('/hod/'.$view, fn () => app(LeadershipPortalPhaseTwoController::class)->hod($view))->middleware('permission:view_hod_department_analytics');
    }

    Route::get('/reports', [LeadershipPortalPhaseTwoController::class, 'reports'])->middleware('permission:view_leadership_reports');
    Route::post('/reports/preview', fn (Request $request) => app(LeadershipPortalPhaseTwoController::class)->report($request))->middleware('permission:view_leadership_reports');
    Route::post('/reports/generate', fn (Request $request) => app(LeadershipPortalPhaseTwoController::class)->report($request, true))->middleware('permission:generate_leadership_reports');

    Route::get('/preferences', [LeadershipPortalPhaseTwoController::class, 'preferences'])->middleware('permission:manage_leadership_preferences');
    Route::put('/preferences', [LeadershipPortalPhaseTwoController::class, 'updatePreferences'])->middleware('permission:manage_leadership_preferences');
    Route::get('/devices', [LeadershipPortalPhaseTwoController::class, 'devices'])->middleware('permission:manage_leadership_devices');
    Route::post('/devices', [LeadershipPortalPhaseTwoController::class, 'registerDevice'])->middleware('permission:manage_leadership_devices');
    Route::delete('/devices/{device}', [LeadershipPortalPhaseTwoController::class, 'revokeDevice'])->middleware('permission:manage_leadership_devices');
});

/*
|--------------------------------------------------------------------------
| Grade Routes
|--------------------------------------------------------------------------
*/

Route::prefix('grades')
    ->middleware($secure)
    ->group(function () {

        Route::get('/', [GradeController::class, 'index']);

        Route::get('/{id}', [GradeController::class, 'show']);

        Route::post('/', [GradeController::class, 'store']);

        Route::put('/{id}', [GradeController::class, 'update']);

        Route::delete('/{id}', [GradeController::class, 'destroy']);

    });

/*
|--------------------------------------------------------------------------
| Stream Routes
|--------------------------------------------------------------------------
*/

Route::prefix('streams')
    ->middleware($secure)
    ->group(function () {

        Route::get('/', [StreamController::class, 'index']);

        Route::get('/{id}', [StreamController::class, 'show']);

        Route::post('/', [StreamController::class, 'store']);

        Route::put('/{id}', [StreamController::class, 'update']);

        Route::delete('/{id}', [StreamController::class, 'destroy']);

    });

/*
|--------------------------------------------------------------------------
| Learner Routes
|--------------------------------------------------------------------------
*/

Route::prefix('learners')
    ->middleware($secure)
    ->group(function () {
        Route::get('/{learner}/guardians', [GuardianLinkController::class, 'index'])
            ->middleware('permission:manage_guardians');

        Route::post('/{learner}/guardians', [GuardianLinkController::class, 'store'])
            ->middleware([
                'permission:manage_guardians',
                'school.operational',
            ]);

        Route::put('/{learner}/guardians/{link}', [GuardianLinkController::class, 'update'])
            ->middleware('permission:manage_guardians');

        Route::delete('/{learner}/guardians/{link}', [GuardianLinkController::class, 'destroy'])
            ->middleware('permission:manage_guardians');

        Route::get('/{learner}/placements', [LearnerPlacementController::class, 'index'])
            ->middleware('permission:manage_learners');

        Route::post('/{learner}/placements', [LearnerPlacementController::class, 'store'])
            ->middleware('permission:manage_learners');

        Route::get('/{learner}/mode-of-study', [LearnerModeOfStudyController::class, 'show'])
            ->middleware('permission:manage_learners');

        Route::patch('/{learner}/mode-of-study', [LearnerModeOfStudyController::class, 'update'])
            ->middleware('permission:manage_learners');

        Route::get('/{learner}/mode-of-study/history', [LearnerModeOfStudyController::class, 'history'])
            ->middleware('permission:manage_learners');

        Route::get('/{learner}/lifecycle', [LearnerLifecycleController::class, 'show'])
            ->middleware('permission:manage_learners');

        Route::patch('/{learner}/lifecycle', [LearnerLifecycleController::class, 'update'])
            ->middleware('permission:manage_learners');

        Route::get('/{learner}/lifecycle/history', [LearnerLifecycleController::class, 'history'])
            ->middleware('permission:manage_learners');

        Route::get('/', [LearnerController::class, 'index'])
            ->middleware('permission:manage_learners');

        Route::post('/{learner}/portal-account', [LearnerPortalAdminController::class, 'create']);
        Route::patch('/{learner}/portal-account/status', [LearnerPortalAdminController::class, 'status']);
        Route::post('/{learner}/portal-account/reset-password', [LearnerPortalAdminController::class, 'reset']);

        Route::get('/{id}', [LearnerController::class, 'show'])
            ->middleware('permission:manage_learners');

        Route::post('/', [LearnerController::class, 'store'])
            ->middleware([
                'permission:manage_learners',
                'school.operational',
            ]);

        Route::put('/{id}', [LearnerController::class, 'update'])
            ->middleware('permission:manage_learners');

        Route::delete('/{id}', [LearnerController::class, 'destroy'])
            ->middleware('permission:manage_learners');
    });

Route::prefix('learner')->middleware($secure)->group(function () {
    Route::get('/behaviour', [BehaviourLearnerController::class, 'index'])->middleware('permission:view_own_behaviour');
    Route::get('/behaviour/recognitions', [BehaviourLearnerController::class, 'recognitions'])->middleware('permission:view_own_recognitions');
    Route::get('/behaviour/actions', [BehaviourLearnerController::class, 'actions'])->middleware('permission:view_own_behaviour');
    Route::get('/homework', [LearnerPortalPhaseTwoController::class, 'homeworkIndex'])->middleware('permission:submit_own_homework');
    Route::get('/homework/{homework}', [LearnerPortalPhaseTwoController::class, 'homeworkShow'])->middleware('permission:submit_own_homework');
    Route::post('/homework/{assignment}/view', [HomeworkLearnerController::class, 'view'])->middleware('permission:submit_own_homework');
    Route::post('/homework/{homework}/submission', [LearnerPortalPhaseTwoController::class, 'saveSubmission'])->middleware('permission:edit_own_homework_drafts');
    Route::put('/homework/{homework}/submission', [LearnerPortalPhaseTwoController::class, 'saveSubmission'])->middleware('permission:edit_own_homework_drafts');
    Route::post('/homework/{homework}/submission/submit', fn (string $homework) => app(LearnerPortalPhaseTwoController::class)->submissionAction($homework, 'submit'))->middleware('permission:submit_own_homework');
    Route::post('/homework/{homework}/submission/withdraw', fn (string $homework) => app(LearnerPortalPhaseTwoController::class)->submissionAction($homework, 'withdraw'))->middleware('permission:withdraw_own_homework_submission');
    Route::post('/homework/{homework}/submission/resubmit', fn (string $homework) => app(LearnerPortalPhaseTwoController::class)->submissionAction($homework, 'resubmit'))->middleware('permission:resubmit_own_homework');
    Route::get('/homework/{homework}/submission/history', [LearnerPortalPhaseTwoController::class, 'submissionHistory'])->middleware('permission:view_own_submission_history');
    Route::post('/homework/{assignment}/submission/files', [HomeworkLearnerController::class, 'upload'])->middleware('permission:upload_learner_portal_files');
    Route::get('/homework/{assignment}/submission', [HomeworkLearnerController::class, 'submission'])->middleware('permission:submit_own_homework');
    Route::get('/homework/{assignment}/submission/files/{file}/download', [HomeworkLearnerController::class, 'download'])->middleware('permission:download_learner_portal_files');
    Route::delete('/homework/{assignment}/submission/files/{file}', [HomeworkLearnerController::class, 'deleteFile'])->middleware('permission:edit_own_homework_drafts');
    Route::post('/homework/{assignment}/submit', [HomeworkLearnerController::class, 'submit'])->middleware('permission:submit_own_homework');
    Route::get('/homework/{assignment}/feedback', [HomeworkLearnerController::class, 'feedback'])->middleware('permission:view_own_submission_history');
    Route::get('/resources', [LearningResourceLearnerController::class, 'index'])->middleware('permission:view_published_learning_resources');
    Route::get('/resources/{resource}', [LearningResourceLearnerController::class, 'show'])->middleware('permission:view_published_learning_resources');
    Route::get('/resources/{resource}/download', [LearningResourceLearnerController::class, 'download'])->middleware('permission:view_published_learning_resources');
    Route::get('/resources/{resource}/open', [LearningResourceLearnerController::class, 'open'])->middleware('permission:view_published_learning_resources');
    Route::post('/resources/{resource}/bookmark', [LearningResourceLearnerController::class, 'bookmark']);
    Route::delete('/resources/{resource}/bookmark', [LearningResourceLearnerController::class, 'unbookmark']);
    Route::put('/resources/{resource}/rating', [LearningResourceLearnerController::class, 'rate']);
    Route::get('/elections', [StudentElectionLearnerController::class, 'index']);
    Route::get('/elections/{election}', [StudentElectionLearnerController::class, 'show']);
    Route::get('/elections/{election}/positions/{position}/candidates', [StudentElectionLearnerController::class, 'candidates']);
    Route::post('/elections/{election}/positions/{position}/vote', [StudentElectionLearnerController::class, 'vote']);
    Route::get('/elections/{election}/results', [StudentElectionLearnerController::class, 'results']);
    Route::get('/student-leaders', [StudentElectionLearnerController::class, 'leaders']);
    Route::get('/me', [LearnerPortalController::class, 'me'])->middleware('permission:access_learner_portal_phase_two');
    Route::get('/dashboard', [LearnerPortalPhaseTwoController::class, 'dashboard'])->middleware('permission:access_learner_portal_phase_two');
    Route::get('/tasks', [LearnerPortalPhaseTwoController::class, 'tasks'])->middleware('permission:access_learner_portal_phase_two');
    Route::get('/analytics', [LearnerPortalPhaseTwoController::class, 'analytics'])->middleware('permission:view_own_learner_analytics');
    Route::get('/dashboard-preferences', [LearnerPortalController::class, 'preferences'])->middleware('permission:manage_own_learner_preferences');
    Route::patch('/dashboard-preferences', [LearnerPortalController::class, 'updatePreferences'])->middleware('permission:manage_own_learner_preferences');
    Route::get('/timetable', [LearnerPortalController::class, 'timetable'])->middleware('permission:view_own_timetable');
    Route::get('/timetable/today', [LearnerPortalController::class, 'timetableToday'])->middleware('permission:view_own_timetable');
    Route::get('/timetable/week', [LearnerPortalController::class, 'timetableWeek'])->middleware('permission:view_own_timetable');
    Route::get('/timetable/current-period', [SmartTimetableController::class, 'currentPeriod'])->middleware('permission:view_own_timetable');
    Route::get('/attendance', [AttendanceLearnerController::class, 'index'])->middleware('permission:view_own_attendance');
    Route::get('/attendance/summary', [AttendanceLearnerController::class, 'summary'])->middleware('permission:view_own_attendance');
    Route::get('/attendance/history', [AttendanceLearnerController::class, 'history'])->middleware('permission:view_own_attendance');
    Route::get('/attendance/calendar', [LearnerPortalPhaseTwoController::class, 'attendanceCalendar'])->middleware('permission:access_learner_portal_phase_two');
    Route::get('/calendar', fn () => app(LearnerPortalPhaseTwoController::class)->calendar())->middleware('permission:access_learner_portal_phase_two');
    Route::get('/calendar/upcoming', fn () => app(LearnerPortalPhaseTwoController::class)->calendar(true))->middleware('permission:access_learner_portal_phase_two');
    Route::get('/results', [LearnerPortalPhaseTwoController::class, 'results'])->middleware('permission:view_own_results');
    Route::get('/results/{exam}', [LearnerPortalPhaseTwoController::class, 'results'])->middleware('permission:view_own_results');
    Route::get('/report-cards', [LearnerPortalController::class, 'reportCards'])->middleware('permission:view_own_report_cards');
    Route::get('/report-cards/{reportCard}/pdf', [LearnerPortalController::class, 'pdf'])->middleware('permission:download_own_report_cards');
    Route::get('/report-cards/{reportCard}/download', [LearnerPortalController::class, 'pdf'])->middleware('permission:download_own_report_cards');
    Route::get('/report-cards/{reportCard}', [LearnerPortalController::class, 'reportCard'])->middleware('permission:view_own_report_cards');
    Route::get('/fees', [FinancePortalController::class, 'learner'])->middleware('permission:view_own_fees');
    Route::get('/fees/summary', [FinancePortalController::class, 'learner'])->middleware('permission:view_own_fees');
    Route::get('/fees/invoices', [FinancePortalController::class, 'learner'])->middleware('permission:view_own_fees');
    Route::get('/fees/payments', [FinancePortalController::class, 'learner'])->middleware('permission:view_own_fees');
    Route::get('/fees/ledger', [FinancePortalController::class, 'learner'])->middleware('permission:view_own_fees');
    Route::get('/fees/receipts/{payment}', [FinancePortalController::class, 'learnerReceipt'])->middleware('permission:view_own_fees');
    foreach (['discounts', 'payment-plans', 'installments', 'refunds', 'arrears', 'statement', 'clearance'] as $benefit) {
        Route::get('/fees/'.$benefit, [FinancePortalController::class, 'learnerBenefits'])->middleware('permission:view_own_fee_benefits');
    }
    Route::get('/upcoming-exams', [LearnerPortalController::class, 'exams'])->middleware('permission:view_own_results');
    Route::get('/progress', [LearnerPortalPhaseTwoController::class, 'progress'])->middleware('permission:view_own_academic_progress');
    Route::get('/progress/learning-areas', [LearnerPortalPhaseTwoController::class, 'progress'])->middleware('permission:view_own_academic_progress');
    Route::get('/progress/learning-areas/{learningArea}', [LearnerPortalPhaseTwoController::class, 'progress'])->middleware('permission:view_own_academic_progress');
    Route::get('/progress/trends', [LearnerPortalPhaseTwoController::class, 'trends'])->middleware('permission:view_own_academic_progress');

    Route::post('/uploads', [LearnerPortalPhaseTwoController::class, 'upload'])->middleware('permission:upload_learner_portal_files');
    Route::get('/uploads/{attachment}/download', [LearnerPortalPhaseTwoController::class, 'attachmentDownload'])->middleware('permission:download_learner_portal_files');
    Route::get('/uploads/{attachment}', [LearnerPortalPhaseTwoController::class, 'attachment'])->middleware('permission:download_learner_portal_files');
    Route::delete('/uploads/{attachment}', [LearnerPortalPhaseTwoController::class, 'attachmentDelete'])->middleware('permission:upload_learner_portal_files');

    Route::get('/learning-resources', [LearnerPortalPhaseTwoController::class, 'resources'])->middleware('permission:view_published_learning_resources');
    Route::get('/learning-resources/{resource}/download', [LearnerPortalPhaseTwoController::class, 'resourceDownload'])->middleware('permission:view_published_learning_resources');
    Route::post('/learning-resources/{resource}/offline', fn (string $resource) => app(LearnerPortalPhaseTwoController::class)->offline($resource))->middleware('permission:manage_own_offline_resources');
    Route::delete('/learning-resources/{resource}/offline', fn (string $resource) => app(LearnerPortalPhaseTwoController::class)->offline($resource, true))->middleware('permission:manage_own_offline_resources');
    Route::get('/learning-resources/{resource}', [LearnerPortalPhaseTwoController::class, 'resource'])->middleware('permission:view_published_learning_resources');
    Route::get('/offline-resources', [LearnerPortalPhaseTwoController::class, 'offlineIndex'])->middleware('permission:manage_own_offline_resources');

    Route::post('/sync/push', [LearnerPortalPhaseTwoController::class, 'syncPush'])->middleware('permission:use_learner_offline_sync');
    Route::get('/sync/pull', [LearnerPortalPhaseTwoController::class, 'syncPull'])->middleware('permission:use_learner_offline_sync');
    Route::get('/sync/status', [LearnerPortalPhaseTwoController::class, 'syncStatus'])->middleware('permission:use_learner_offline_sync');
    Route::get('/sync/conflicts', [LearnerPortalPhaseTwoController::class, 'syncConflicts'])->middleware('permission:use_learner_offline_sync');
    Route::post('/sync/conflicts/{conflict}/resolve', [LearnerPortalPhaseTwoController::class, 'syncResolve'])->middleware('permission:resolve_own_learner_sync_conflicts');

    Route::get('/devices', [LearnerPortalPhaseTwoController::class, 'devices'])->middleware('permission:manage_own_learner_devices');
    Route::post('/devices', [LearnerPortalPhaseTwoController::class, 'registerDevice'])->middleware('permission:manage_own_learner_devices');
    Route::delete('/devices/{device}', [LearnerPortalPhaseTwoController::class, 'revokeDevice'])->middleware('permission:manage_own_learner_devices');
    Route::post('/devices/{device}/push-token', fn (Request $request, string $device) => app(LearnerPortalPhaseTwoController::class)->pushToken($request, $device))->middleware('permission:manage_own_learner_push_token');
    Route::delete('/devices/{device}/push-token', fn (Request $request, string $device) => app(LearnerPortalPhaseTwoController::class)->pushToken($request, $device, true))->middleware('permission:manage_own_learner_push_token');
    Route::get('/push/deliveries', [LearnerPortalPhaseTwoController::class, 'pushDeliveries'])->middleware('permission:view_own_push_delivery_status');

    Route::get('/communications', [LearnerPortalPhaseTwoController::class, 'communications'])->middleware('permission:access_learner_portal_phase_two');
    Route::get('/communications/{communication}', [LearnerPortalPhaseTwoController::class, 'communications'])->middleware('permission:access_learner_portal_phase_two');
    Route::post('/help-requests', [LearnerPortalPhaseTwoController::class, 'createHelp'])->middleware(['permission:create_learner_help_requests', 'throttle:10,1']);
    Route::get('/help-requests', [LearnerPortalPhaseTwoController::class, 'help'])->middleware('permission:view_own_help_requests');
    Route::get('/help-requests/{help}', [LearnerPortalPhaseTwoController::class, 'help'])->middleware('permission:view_own_help_requests');

    Route::get('/notifications/unread-count', [LearnerPortalPhaseTwoController::class, 'unread'])->middleware('permission:view_own_notifications');
    Route::get('/notifications', [LearnerPortalPhaseTwoController::class, 'notifications'])->middleware('permission:view_own_notifications');
    foreach (['read', 'unread', 'archive', 'dismiss'] as $state) {
        Route::post('/notifications/{notification}/'.$state, fn (string $notification) => app(LearnerPortalPhaseTwoController::class)->notificationState($notification, $state === 'archive' ? 'archived' : ($state === 'dismiss' ? 'dismissed' : $state)))->middleware('permission:view_own_notifications');
    }
    Route::get('/announcements/{announcement}', [LearnerPortalPhaseTwoController::class, 'announcements'])->middleware('permission:view_learner_announcements');
    Route::get('/announcements', [LearnerPortalPhaseTwoController::class, 'announcements'])->middleware('permission:view_learner_announcements');
    Route::post('/announcements/{announcement}/read', [LearnerPortalPhaseTwoController::class, 'announcementRead'])->middleware('permission:view_learner_announcements');

    Route::get('/profile', [LearnerPortalPhaseTwoController::class, 'profile'])->middleware('permission:update_own_learner_profile');
    Route::put('/profile', [LearnerPortalPhaseTwoController::class, 'updatePreferences'])->middleware('permission:update_own_learner_profile');
    Route::get('/preferences', [LearnerPortalPhaseTwoController::class, 'preferences'])->middleware('permission:manage_own_learner_preferences');
    Route::put('/preferences', [LearnerPortalPhaseTwoController::class, 'updatePreferences'])->middleware('permission:manage_own_learner_preferences');
});

Route::prefix('learning-resources')->middleware($secure)->group(function () {
    Route::get('/analytics', [LearningResourceAdminController::class, 'analytics'])->middleware('permission:view_learning_resource_analytics');
    Route::get('/', [LearningResourceAdminController::class, 'index']);
    Route::get('/{resource}', [LearningResourceAdminController::class, 'show']);
    Route::get('/{resource}/versions', [LearningResourceAdminController::class, 'versions']);
    Route::post('/{resource}/approve', fn (Request $r, string $resource) => app(LearningResourceAdminController::class)->transition($r, $resource, 'approved'))->middleware('permission:approve_learning_resources');
    Route::post('/{resource}/reject', fn (Request $r, string $resource) => app(LearningResourceAdminController::class)->transition($r, $resource, 'rejected'))->middleware('permission:approve_learning_resources');
    Route::post('/{resource}/publish', fn (Request $r, string $resource) => app(LearningResourceAdminController::class)->transition($r, $resource, 'published'))->middleware('permission:publish_learning_resources');
    Route::post('/{resource}/archive', fn (Request $r, string $resource) => app(LearningResourceAdminController::class)->transition($r, $resource, 'archived'))->middleware('permission:archive_learning_resources');
});

Route::prefix('learning-resource-categories')->middleware(['jwt', 'tenant'])->group(function () {
    Route::get('/', [LearningResourceCategoryController::class, 'index']);
    Route::post('/', [LearningResourceCategoryController::class, 'store'])->middleware('permission:manage_learning_resource_categories');
    Route::put('/{category}', [LearningResourceCategoryController::class, 'update'])->middleware('permission:manage_learning_resource_categories');
    Route::delete('/{category}', [LearningResourceCategoryController::class, 'destroy'])->middleware('permission:manage_learning_resource_categories');
});

Route::prefix('student-elections')->middleware($secure)->group(function () {
    Route::get('/', [StudentElectionAdminController::class, 'index']);
    Route::post('/', [StudentElectionAdminController::class, 'create']);
    Route::get('/{election}', [StudentElectionAdminController::class, 'show']);
    Route::post('/{election}/positions', [StudentElectionAdminController::class, 'attach']);
    Route::post('/{election}/generate-voters', [StudentElectionAdminController::class, 'voters']);
    foreach (['nominations_open' => 'open-nominations', 'nominations_closed' => 'close-nominations', 'voting_open' => 'open-voting', 'voting_closed' => 'close-voting', 'cancelled' => 'cancel'] as $status => $path) {
        Route::post('/{election}/'.$path, fn (Request $r, string $election) => app(StudentElectionAdminController::class)->transition($r, $election, $status));
    }Route::post('/{election}/tally', [StudentElectionAdminController::class, 'tally']);
    Route::post('/{election}/publish', [StudentElectionAdminController::class, 'publish']);
    Route::get('/{election}/results', [StudentElectionAdminController::class, 'results']);
});
Route::prefix('student-leadership-positions')->middleware($secure)->group(function () {
    Route::get('/', [StudentElectionAdminController::class, 'positions']);
    Route::post('/', [StudentElectionAdminController::class, 'createPosition']);
});
Route::prefix('student-election-candidates')->middleware($secure)->group(function () {
    Route::patch('/{candidate}/approve', fn (Request $r, string $candidate) => app(StudentElectionAdminController::class)->review($r, $candidate, 'approved'));
    Route::patch('/{candidate}/reject', fn (Request $r, string $candidate) => app(StudentElectionAdminController::class)->review($r, $candidate, 'rejected'));
    Route::patch('/{candidate}/disqualify', fn (Request $r, string $candidate) => app(StudentElectionAdminController::class)->review($r, $candidate, 'disqualified'));
});

/*
|--------------------------------------------------------------------------
| Guardian Routes
|--------------------------------------------------------------------------
*/

Route::prefix('guardians')
    ->middleware($secure)
    ->group(function () {
        Route::get('/', [GuardianController::class, 'index'])
            ->middleware('permission:manage_guardians');

        Route::get('/{id}', [GuardianController::class, 'show'])
            ->middleware('permission:manage_guardians');

        Route::post('/', [GuardianController::class, 'store'])
            ->middleware([
                'permission:manage_guardians',
                'school.operational',
            ]);

        Route::put('/{id}', [GuardianController::class, 'update'])
            ->middleware('permission:manage_guardians');

        Route::delete('/{id}', [GuardianController::class, 'destroy'])
            ->middleware('permission:manage_guardians');
    });

/*
|--------------------------------------------------------------------------
| Learning Area Routes
|--------------------------------------------------------------------------
*/

Route::prefix('learning-areas')
    ->middleware($secure)
    ->group(function () {

        Route::get('/', [LearningAreaController::class, 'index']);

        Route::get('/{id}', [LearningAreaController::class, 'show']);

        Route::post('/', [LearningAreaController::class, 'store']);

        Route::put('/{id}', [LearningAreaController::class, 'update']);

        Route::delete('/{id}', [LearningAreaController::class, 'destroy']);

    });

/*
|--------------------------------------------------------------------------
| Learning Area Allocation Routes
|--------------------------------------------------------------------------
*/

Route::prefix('learning-area-allocations')
    ->middleware($secure)
    ->group(function () {

        Route::get('/', [LearningAreaAllocationController::class, 'index']);

        Route::get('/{id}', [LearningAreaAllocationController::class, 'show']);

        Route::post('/', [LearningAreaAllocationController::class, 'store']);

        Route::put('/{id}', [LearningAreaAllocationController::class, 'update']);

        Route::delete('/{id}', [LearningAreaAllocationController::class, 'destroy']);

    });

/*
|--------------------------------------------------------------------------
/*
|--------------------------------------------------------------------------
| Academic Week Routes
|--------------------------------------------------------------------------
*/

Route::prefix('academic-weeks')
    ->middleware($secure)
    ->group(function () {

        Route::get('/', [AcademicWeekController::class, 'index']);

        Route::get('/{id}', [AcademicWeekController::class, 'show']);

        Route::post('/', [AcademicWeekController::class, 'store']);

        Route::put('/{id}', [AcademicWeekController::class, 'update']);

        Route::delete('/{id}', [AcademicWeekController::class, 'destroy']);

    });

/*
|--------------------------------------------------------------------------
| Teaching Engine Routes
|--------------------------------------------------------------------------
*/

Route::prefix('teacher-assignments')
    ->middleware($secure)
    ->group(function () {

        Route::get('/', [TeacherAssignmentController::class, 'index']);

        Route::get('/{id}', [TeacherAssignmentController::class, 'show']);

        Route::post('/', [TeacherAssignmentController::class, 'store']);

        Route::put('/{id}', [TeacherAssignmentController::class, 'update']);

        Route::delete('/{id}', [TeacherAssignmentController::class, 'destroy']);

    });

Route::prefix('schemes-of-work')
    ->middleware($secure)
    ->group(function () {

        Route::get('/', [SchemeOfWorkController::class, 'index']);

        Route::get('/{id}', [SchemeOfWorkController::class, 'show']);

        Route::post('/', [SchemeOfWorkController::class, 'store']);

        Route::put('/{id}', [SchemeOfWorkController::class, 'update']);

        Route::delete('/{id}', [SchemeOfWorkController::class, 'destroy']);

    });

Route::prefix('scheme-lessons')
    ->middleware($secure)
    ->group(function () {

        Route::get('/', [SchemeLessonController::class, 'index']);

        Route::get('/{id}', [SchemeLessonController::class, 'show']);

        Route::post('/', [SchemeLessonController::class, 'store']);

        Route::put('/{id}', [SchemeLessonController::class, 'update']);

        Route::delete('/{id}', [SchemeLessonController::class, 'destroy']);

    });

Route::prefix('lesson-plans')
    ->middleware($secure)
    ->group(function () {

        Route::get('/', [LessonPlanController::class, 'index']);

        Route::get('/{id}', [LessonPlanController::class, 'show']);

        Route::post('/', [LessonPlanController::class, 'store']);

        Route::put('/{id}', [LessonPlanController::class, 'update']);

        Route::delete('/{id}', [LessonPlanController::class, 'destroy']);

    });

Route::prefix('lesson-notes')
    ->middleware($secure)
    ->group(function () {

        Route::get('/', [LessonNoteController::class, 'index']);

        Route::get('/{id}', [LessonNoteController::class, 'show']);

        Route::post('/', [LessonNoteController::class, 'store']);

        Route::put('/{id}', [LessonNoteController::class, 'update']);

        Route::delete('/{id}', [LessonNoteController::class, 'destroy']);

    });

Route::prefix('records-of-work')
    ->middleware($secure)
    ->group(function () {

        Route::get('/', [RecordOfWorkController::class, 'index']);

        Route::get('/{id}', [RecordOfWorkController::class, 'show']);

        Route::post('/', [RecordOfWorkController::class, 'store']);

        Route::put('/{id}', [RecordOfWorkController::class, 'update']);

        Route::delete('/{id}', [RecordOfWorkController::class, 'destroy']);

    });

Route::prefix('curriculum-coverage')
    ->middleware($secure)
    ->group(function () {

        Route::get('/', [CurriculumCoverageController::class, 'index']);

        Route::get('/{id}', [CurriculumCoverageController::class, 'show']);

        Route::post('/', [CurriculumCoverageController::class, 'store']);

        Route::put('/{id}', [CurriculumCoverageController::class, 'update']);

        Route::delete('/{id}', [CurriculumCoverageController::class, 'destroy']);

    });
/*
|--------------------------------------------------------------------------
| Exams Engine Routes
|--------------------------------------------------------------------------
*/

/*
|--------------------------------------------------------------------------
| Exam Engine Routes
|--------------------------------------------------------------------------
*/

Route::prefix('assessment-types')
    ->middleware($secure)
    ->group(function () {

        Route::get('/', [AssessmentTypeController::class, 'index']);

        Route::get('/{id}', [AssessmentTypeController::class, 'show']);

        Route::post('/', [AssessmentTypeController::class, 'store']);

        Route::put('/{id}', [AssessmentTypeController::class, 'update']);

        Route::delete('/{id}', [AssessmentTypeController::class, 'destroy']);

    });

Route::prefix('assessment-registrations')
    ->middleware($secure)
    ->group(function () {

        Route::get('/', [AssessmentRegistrationController::class, 'index']);

        Route::get('/{id}', [AssessmentRegistrationController::class, 'show']);

        Route::post('/', [AssessmentRegistrationController::class, 'store']);

        Route::put('/{id}', [AssessmentRegistrationController::class, 'update']);

        Route::delete('/{id}', [AssessmentRegistrationController::class, 'destroy']);

    });

Route::prefix('exams')
    ->middleware($secure)
    ->group(function () {

        Route::get('/', [ExamController::class, 'index']);

        Route::get('/{id}', [ExamController::class, 'show']);

        Route::post('/', [ExamController::class, 'store']);

        Route::put('/{id}', [ExamController::class, 'update']);

        Route::delete('/{id}', [ExamController::class, 'destroy']);

    });

Route::prefix('exam-learning-areas')
    ->middleware($secure)
    ->group(function () {

        Route::get('/', [ExamLearningAreaController::class, 'index']);

        Route::get('/{id}', [ExamLearningAreaController::class, 'show']);

        Route::post('/', [ExamLearningAreaController::class, 'store']);

        Route::put('/{id}', [ExamLearningAreaController::class, 'update']);

        Route::delete('/{id}', [ExamLearningAreaController::class, 'destroy']);

    });

Route::prefix('exam-papers')
    ->middleware($secure)
    ->group(function () {

        Route::get('/', [ExamPaperController::class, 'index']);

        Route::get('/{id}', [ExamPaperController::class, 'show']);

        Route::post('/', [ExamPaperController::class, 'store']);

        Route::put('/{id}', [ExamPaperController::class, 'update']);

        Route::delete('/{id}', [ExamPaperController::class, 'destroy']);

    });

Route::prefix('exam-results')
    ->middleware($secure)
    ->group(function () {

        Route::get('/', [ExamResultController::class, 'index']);

        Route::get('/{id}', [ExamResultController::class, 'show']);

        Route::post('/', [ExamResultController::class, 'store']);

        Route::put('/{id}', [ExamResultController::class, 'update']);

        Route::delete('/{id}', [ExamResultController::class, 'destroy']);

    });

Route::prefix('learning-area-results')
    ->middleware($secure)
    ->group(function () {
        Route::get('/', [LearningAreaResultController::class, 'index']);
        Route::post('/process', [LearningAreaResultController::class, 'process']);
        Route::get('/{id}', [LearningAreaResultController::class, 'show']);
    });

Route::prefix('mark-entry-permissions')
    ->middleware($secure)
    ->group(function () {

        Route::get('/', [MarkEntryPermissionController::class, 'index']);

        Route::get('/{id}', [MarkEntryPermissionController::class, 'show']);

        Route::post('/', [MarkEntryPermissionController::class, 'store']);

        Route::put('/{id}', [MarkEntryPermissionController::class, 'update']);

        Route::delete('/{id}', [MarkEntryPermissionController::class, 'destroy']);

    });

Route::prefix('merit-lists')
    ->middleware($secure)
    ->group(function () {
        Route::get('/', [MeritListController::class, 'index']);
        Route::post('/generate', [MeritListController::class, 'generate']);
        Route::post('/publish', [MeritListController::class, 'publish']);
        Route::get('/{id}', [MeritListController::class, 'show']);
    });

Route::prefix('report-cards')
    ->middleware($secure)
    ->group(function () {

        Route::get('/', [ReportCardController::class, 'index']);
        Route::post('/generate', [ReportCardController::class, 'generate']);
        Route::post('/publish', [ReportCardController::class, 'publish']);
        Route::patch('/{id}/comments', [ReportCardController::class, 'updateComments']);
        Route::get('/{id}/pdf/download', [ReportCardPdfController::class, 'download']);
        Route::get('/{id}/pdf', [ReportCardPdfController::class, 'stream']);
        Route::get('/{id}', [ReportCardController::class, 'show']);

    });

Route::prefix('parent')->middleware($secure)->group(function () {
    Route::get('/phase-two/dashboard', [ParentPortalPhaseTwoController::class, 'dashboard'])->middleware('permission:access_parent_portal_phase_two');
    Route::get('/tasks', [ParentPortalPhaseTwoController::class, 'tasks'])->middleware('permission:view_own_parent_tasks');
    Route::get('/analytics', [ParentPortalPhaseTwoController::class, 'analytics'])->middleware('permission:view_own_parent_analytics');
    Route::get('/payments/provider-health', [ParentPortalPhaseTwoController::class, 'providerHealth'])->middleware('permission:initiate_linked_learner_payments');
    Route::get('/payments', [ParentPortalPhaseTwoController::class, 'payments'])->middleware('permission:view_own_payment_attempts');
    Route::post('/payments/{paymentAttempt}/cancel', [ParentPortalPhaseTwoController::class, 'cancelPayment'])->middleware('permission:initiate_linked_learner_payments');
    Route::get('/payments/{paymentAttempt}', [ParentPortalPhaseTwoController::class, 'payment'])->middleware('permission:view_own_payment_attempts');
    Route::post('/children/{learner}/payments/stk/preview', [ParentPortalPhaseTwoController::class, 'paymentPreview'])->middleware('permission:initiate_linked_learner_payments');
    Route::post('/children/{learner}/payments/stk/initiate', [ParentPortalPhaseTwoController::class, 'paymentInitiate'])->middleware('permission:initiate_linked_learner_payments');
    Route::get('/children/{learner}/payments', [ParentPortalPhaseTwoController::class, 'payments'])->middleware('permission:view_own_payment_attempts');
    Route::get('/children/{learner}/payments/{payment}', [ParentPortalPhaseTwoController::class, 'financePayment'])->middleware('permission:view_own_payment_attempts');
    Route::get('/children/{learner}/receipts/{payment}/download', [ParentPortalPhaseTwoController::class, 'downloadReceipt'])->middleware('permission:download_own_payment_receipts');

    Route::get('/conversations', [ParentPortalPhaseTwoController::class, 'conversations'])->middleware('permission:view_own_parent_conversations');
    Route::post('/conversations', [ParentPortalPhaseTwoController::class, 'createConversation'])->middleware(['permission:create_parent_conversations', 'throttle:10,1']);
    Route::get('/conversations/{conversation}/messages', [ParentPortalPhaseTwoController::class, 'messages'])->middleware('permission:view_own_parent_conversations');
    Route::post('/conversations/{conversation}/messages', [ParentPortalPhaseTwoController::class, 'sendMessage'])->middleware(['permission:send_parent_messages', 'throttle:20,1']);
    Route::post('/conversations/{conversation}/close', [ParentPortalPhaseTwoController::class, 'closeConversation'])->middleware('permission:create_parent_conversations');
    Route::get('/conversations/{conversation}', [ParentPortalPhaseTwoController::class, 'conversation'])->middleware('permission:view_own_parent_conversations');

    Route::get('/consents', [ParentPortalPhaseTwoController::class, 'consents'])->middleware('permission:view_linked_learner_consents');
    Route::post('/consents/{consent}/accept', fn (Request $request, string $consent) => app(ParentPortalPhaseTwoController::class)->respondConsent($request, $consent, 'accepted'))->middleware('permission:respond_to_parent_consents');
    Route::post('/consents/{consent}/decline', fn (Request $request, string $consent) => app(ParentPortalPhaseTwoController::class)->respondConsent($request, $consent, 'declined'))->middleware('permission:respond_to_parent_consents');
    Route::get('/consents/{consent}', [ParentPortalPhaseTwoController::class, 'consent'])->middleware('permission:view_linked_learner_consents');
    Route::get('/children/{learner}/consents', [ParentPortalPhaseTwoController::class, 'learnerConsents'])->middleware('permission:view_linked_learner_consents');

    Route::get('/appointments', [ParentPortalPhaseTwoController::class, 'appointments'])->middleware('permission:manage_own_parent_appointments');
    Route::post('/appointments', [ParentPortalPhaseTwoController::class, 'createAppointment'])->middleware(['permission:create_parent_appointments', 'throttle:10,1']);
    foreach (['accept-proposal', 'decline-proposal', 'cancel'] as $action) {
        Route::post('/appointments/{appointment}/'.$action, fn (string $appointment) => app(ParentPortalPhaseTwoController::class)->appointmentAction($appointment, $action))->middleware('permission:manage_own_parent_appointments');
    }
    Route::get('/appointments/{appointment}', [ParentPortalPhaseTwoController::class, 'appointment'])->middleware('permission:manage_own_parent_appointments');

    Route::get('/children/{learner}/progress/{section}', [ParentPortalPhaseTwoController::class, 'progress'])->whereIn('section', ['academics', 'attendance', 'homework', 'trends'])->middleware('permission:view_linked_learner_progress');
    Route::get('/children/{learner}/progress', [ParentPortalPhaseTwoController::class, 'progress'])->middleware('permission:view_linked_learner_progress');
    Route::post('/sync/push', [ParentPortalPhaseTwoController::class, 'syncPush'])->middleware('permission:use_parent_offline_sync');
    Route::get('/sync/pull', [ParentPortalPhaseTwoController::class, 'syncPull'])->middleware('permission:use_parent_offline_sync');
    Route::get('/sync/status', [ParentPortalPhaseTwoController::class, 'syncStatus'])->middleware('permission:use_parent_offline_sync');
    Route::get('/sync/conflicts', [ParentPortalPhaseTwoController::class, 'syncConflicts'])->middleware('permission:use_parent_offline_sync');
    Route::post('/sync/conflicts/{conflict}/resolve', [ParentPortalPhaseTwoController::class, 'resolveConflict'])->middleware('permission:resolve_own_parent_sync_conflicts');
    Route::post('/uploads', [ParentPortalPhaseTwoController::class, 'upload'])->middleware('permission:upload_parent_portal_files');
    Route::get('/uploads/{attachment}/download', [ParentPortalPhaseTwoController::class, 'downloadAttachment'])->middleware('permission:download_parent_portal_files');
    Route::get('/uploads/{attachment}', [ParentPortalPhaseTwoController::class, 'attachment'])->middleware('permission:download_parent_portal_files');
    Route::delete('/uploads/{attachment}', [ParentPortalPhaseTwoController::class, 'deleteAttachment'])->middleware('permission:upload_parent_portal_files');
    Route::post('/devices/{device}/push-token', [ParentPortalPhaseTwoController::class, 'updatePushToken'])->middleware('permission:manage_own_parent_push_token');
    Route::delete('/devices/{device}/push-token', [ParentPortalPhaseTwoController::class, 'deletePushToken'])->middleware('permission:manage_own_parent_push_token');
    Route::get('/push/deliveries', [ParentPortalPhaseTwoController::class, 'pushDeliveries'])->middleware('permission:view_own_parent_push_deliveries');
    Route::get('/dashboard', [ParentPortalPhaseTwoController::class, 'dashboard'])->middleware('permission:access_parent_portal_phase_two');
    Route::get('/children', [ParentPortalMobileController::class, 'children'])->middleware('permission:view_linked_learners');
    Route::get('/children/{learner}/profile', [ParentPortalMobileController::class, 'childProfile'])->middleware('permission:view_linked_learner_profile');
    Route::get('/children/{learner}/attendance/summary', [ParentPortalMobileController::class, 'attendanceSummary'])->middleware('permission:view_linked_learner_attendance');
    Route::get('/children/{learner}/attendance', [ParentPortalMobileController::class, 'attendance'])->middleware('permission:view_linked_learner_attendance');
    Route::get('/children/{learner}/timetable/today', [ParentPortalMobileController::class, 'timetableToday'])->middleware('permission:view_linked_learner_timetable');
    Route::get('/children/{learner}/timetable', [ParentPortalMobileController::class, 'timetable'])->middleware('permission:view_linked_learner_timetable');
    Route::get('/children/{learner}/homework/{homework}', [ParentPortalMobileController::class, 'homeworkShow'])->middleware('permission:view_linked_learner_homework');
    Route::get('/children/{learner}/homework', [ParentPortalMobileController::class, 'homework'])->middleware('permission:view_linked_learner_homework');
    Route::get('/children/{learner}/learning-resources/{resource}/download', [ParentPortalMobileController::class, 'learningResourceDownload'])->middleware('permission:view_linked_learner_learning_resources');
    Route::get('/children/{learner}/learning-resources/{resource}', [ParentPortalMobileController::class, 'learningResource'])->middleware('permission:view_linked_learner_learning_resources');
    Route::get('/children/{learner}/learning-resources', [ParentPortalMobileController::class, 'learningResources'])->middleware('permission:view_linked_learner_learning_resources');
    Route::get('/children/{learner}/results/{exam}', [ParentPortalMobileController::class, 'result'])->middleware('permission:view_linked_learner_results');
    Route::get('/children/{learner}/results', [ParentPortalMobileController::class, 'results'])->middleware('permission:view_linked_learner_results');
    Route::get('/children/{learner}/report-cards/{reportCard}/download', [ParentPortalMobileController::class, 'reportCardDownload'])->middleware('permission:download_linked_learner_report_cards');
    Route::get('/children/{learner}/report-cards/{reportCard}', [ParentPortalMobileController::class, 'reportCard'])->middleware('permission:view_linked_learner_report_cards');
    Route::get('/children/{learner}/report-cards', [ParentPortalMobileController::class, 'reportCards'])->middleware('permission:view_linked_learner_report_cards');
    Route::get('/children/{learner}/finance/summary', [ParentPortalMobileController::class, 'financeSummary'])->middleware('permission:view_linked_learner_finance');
    Route::get('/children/{learner}/finance/statement', [ParentPortalMobileController::class, 'financeStatement'])->middleware('permission:view_linked_learner_finance');
    Route::get('/children/{learner}/finance/invoices', [ParentPortalMobileController::class, 'financeInvoices'])->middleware('permission:view_linked_learner_finance');
    Route::get('/children/{learner}/finance/payments', [ParentPortalMobileController::class, 'financePayments'])->middleware('permission:view_linked_learner_finance');
    Route::get('/children/{learner}/finance/receipts/{payment}', [ParentPortalMobileController::class, 'receipt'])->middleware('permission:view_linked_learner_finance');
    Route::get('/children/{learner}/documents/{document}/download', [ParentPortalMobileController::class, 'documentDownload'])->middleware('permission:view_parent_documents');
    Route::get('/children/{learner}/documents', [ParentPortalMobileController::class, 'documents'])->middleware('permission:view_parent_documents');
    Route::get('/children/{learner}', [ParentPortalMobileController::class, 'child'])->middleware('permission:view_linked_learners');

    Route::get('/communications/{communication}', [ParentPortalMobileController::class, 'communications'])->middleware('permission:view_parent_communications');
    Route::get('/communications', [ParentPortalMobileController::class, 'communications'])->middleware('permission:view_parent_communications');
    Route::get('/notifications/unread-count', [ParentPortalMobileController::class, 'notificationUnreadCount'])->middleware('permission:view_parent_communications');
    Route::post('/notifications/{notification}/read', [ParentPortalMobileController::class, 'notificationRead'])->middleware('permission:view_parent_communications');
    Route::post('/notifications/{notification}/unread', [ParentPortalMobileController::class, 'notificationUnread'])->middleware('permission:view_parent_communications');
    Route::post('/notifications/{notification}/archive', [ParentPortalMobileController::class, 'notificationArchive'])->middleware('permission:view_parent_communications');
    Route::post('/notifications/{notification}/dismiss', [ParentPortalMobileController::class, 'notificationDismiss'])->middleware('permission:view_parent_communications');
    Route::get('/notifications', [ParentPortalMobileController::class, 'notificationIndex'])->middleware('permission:view_parent_communications');
    Route::post('/announcements/{announcement}/read', [ParentPortalMobileController::class, 'announcementRead'])->middleware('permission:view_parent_announcements');
    Route::get('/announcements/{announcement}', [ParentPortalMobileController::class, 'announcements'])->middleware('permission:view_parent_announcements');
    Route::get('/announcements', [ParentPortalMobileController::class, 'announcements'])->middleware('permission:view_parent_announcements');
    Route::get('/calendar/upcoming', [ParentPortalMobileController::class, 'calendarUpcoming'])->middleware('permission:view_parent_calendar');
    Route::get('/calendar', [ParentPortalMobileController::class, 'calendarIndex'])->middleware('permission:view_parent_calendar');
    Route::get('/profile', [ParentPortalMobileController::class, 'profile'])->middleware('permission:access_parent_portal');
    Route::put('/profile', [ParentPortalMobileController::class, 'updateProfile'])->middleware('permission:update_own_parent_profile');
    Route::get('/preferences', [ParentPortalMobileController::class, 'preferences'])->middleware('permission:manage_own_parent_preferences');
    Route::put('/preferences', [ParentPortalMobileController::class, 'updatePreferences'])->middleware('permission:manage_own_parent_preferences');
    Route::get('/devices', [ParentPortalMobileController::class, 'devices'])->middleware('permission:manage_own_parent_devices');
    Route::post('/devices', [ParentPortalMobileController::class, 'registerDevice'])->middleware('permission:manage_own_parent_devices');
    Route::delete('/devices/{device}', [ParentPortalMobileController::class, 'revokeDevice'])->middleware('permission:manage_own_parent_devices');

    // Safe legacy aliases retained for existing parent clients.
    Route::get('/learners/{learner}/timetable', [ParentPortalMobileController::class, 'timetable'])->middleware('permission:view_linked_learner_timetable');
    Route::get('/learners/{learner}/timetable/today', [ParentPortalMobileController::class, 'timetableToday'])->middleware('permission:view_linked_learner_timetable');
    Route::get('/learners/{learner}/behaviour', [BehaviourParentController::class, 'index'])->middleware('permission:view_linked_learner_behaviour');
    Route::get('/learners/{learner}/behaviour/recognitions', [BehaviourParentController::class, 'recognitions'])->middleware('permission:view_linked_learner_recognitions');
    Route::get('/learners/{learner}/behaviour/actions', [BehaviourParentController::class, 'actions'])->middleware('permission:view_linked_learner_behaviour');
    Route::get('/learners/{learner}/homework', [ParentPortalMobileController::class, 'homework'])->middleware('permission:view_linked_learner_homework');
    Route::get('/learners/{learner}/homework/{homework}', [ParentPortalMobileController::class, 'homeworkShow'])->middleware('permission:view_linked_learner_homework');
    Route::get('/learners/{learner}/resources', [ParentPortalMobileController::class, 'learningResources'])->middleware('permission:view_linked_learner_learning_resources');
    Route::get('/learners/{learner}/resources/{resource}', [ParentPortalMobileController::class, 'learningResource'])->middleware('permission:view_linked_learner_learning_resources');
    Route::get('/learners/{learner}/resources/{resource}/download', [ParentPortalMobileController::class, 'learningResourceDownload'])->middleware('permission:view_linked_learner_learning_resources');
    Route::get('/me', [ParentPortalMobileController::class, 'profile'])->middleware('permission:access_parent_portal');
    Route::get('/learners', [ParentPortalMobileController::class, 'children'])->middleware('permission:view_linked_learners');
    Route::get('/learners/{learner}/dashboard', [ParentPortalMobileController::class, 'child'])->middleware('permission:view_linked_learners');
    Route::get('/learners/{learner}/report-cards', [ParentPortalMobileController::class, 'reportCards'])->middleware('permission:view_linked_learner_report_cards');
    Route::get('/learners/{learner}/report-cards/{reportCard}/pdf', [ParentPortalMobileController::class, 'reportCardDownload'])->middleware('permission:download_linked_learner_report_cards');
    Route::get('/learners/{learner}/report-cards/{reportCard}', [ParentPortalMobileController::class, 'reportCard'])->middleware('permission:view_linked_learner_report_cards');
    Route::get('/learners/{learner}/attendance', [ParentPortalMobileController::class, 'attendance'])->middleware('permission:view_linked_learner_attendance');
    Route::get('/learners/{learner}/attendance/summary', [ParentPortalMobileController::class, 'attendanceSummary'])->middleware('permission:view_linked_learner_attendance');
    Route::get('/learners/{learner}/attendance/history', [ParentPortalMobileController::class, 'attendance'])->middleware('permission:view_linked_learner_attendance');
    Route::get('/learners/{learner}/fees', [ParentPortalMobileController::class, 'financeSummary'])->middleware('permission:view_linked_learner_fees');
    Route::get('/learners/{learner}/fees/summary', [ParentPortalMobileController::class, 'financeSummary'])->middleware('permission:view_linked_learner_fees');
    Route::get('/learners/{learner}/fees/invoices', [ParentPortalMobileController::class, 'financeInvoices'])->middleware('permission:view_linked_learner_fees');
    Route::get('/learners/{learner}/fees/payments', [ParentPortalMobileController::class, 'financePayments'])->middleware('permission:view_linked_learner_fees');
    Route::get('/learners/{learner}/fees/ledger', [ParentPortalMobileController::class, 'financeStatement'])->middleware('permission:view_linked_learner_fees');
    Route::get('/learners/{learner}/fees/receipts/{payment}', [ParentPortalMobileController::class, 'receipt'])->middleware('permission:view_linked_learner_fees');
    Route::get('/learners/{learner}/fees/statement', [ParentPortalMobileController::class, 'financeStatement'])->middleware('permission:view_linked_learner_fee_benefits');
    foreach (['discounts', 'payment-plans', 'installments', 'refunds', 'arrears', 'clearance'] as $benefit) {
        Route::get('/learners/{learner}/fees/'.$benefit, [FinancePortalController::class, 'parentBenefits'])->middleware('permission:view_linked_learner_fee_benefits');
    }
});

Route::prefix('homework')->middleware($secure)->group(function () {
    Route::get('/analytics', [HomeworkLeadershipController::class, 'analytics']);
    Route::post('/submissions/{submission}/request-moderation', fn (Request $r, string $submission) => app(HomeworkLeadershipController::class)->moderation($r, $submission));
    Route::post('/submissions/{submission}/moderate', fn (Request $r, string $submission) => app(HomeworkLeadershipController::class)->moderation($r, $submission, true));
    Route::get('/', [HomeworkLeadershipController::class, 'index']);
    Route::get('/{assignment}/completion', [HomeworkLeadershipController::class, 'completion']);
    Route::get('/{assignment}', [HomeworkLeadershipController::class, 'show']);
});
Route::prefix('parent-access-policy')->middleware($secure)->group(function () {
    Route::get('/', [ParentPortalAdminController::class, 'policy']);
    Route::put('/', [ParentPortalAdminController::class, 'updatePolicy']);
});
Route::prefix('parent-access-overrides')->middleware($secure)->group(function () {
    Route::get('/', [ParentPortalAdminController::class, 'overrides']);
    Route::post('/', [ParentPortalAdminController::class, 'createOverride']);
    Route::delete('/{id}', [ParentPortalAdminController::class, 'revokeOverride']);
});

/*
|--------------------------------------------------------------------------
| Attendance Engine Routes
|--------------------------------------------------------------------------
*/

Route::prefix('attendance')->middleware($secure)->group(function () {
    Route::get('/risk-flags', [BehaviourLeadershipController::class, 'risks'])->middleware('permission:view_attendance_risk_flags');
    Route::post('/risk-flags/{flag}/acknowledge', fn (Request $request, string $flag) => app(BehaviourLeadershipController::class)->riskUpdate($request, $flag, 'acknowledged'))->middleware('permission:resolve_attendance_risk_flags');
    Route::post('/risk-flags/{flag}/resolve', fn (Request $request, string $flag) => app(BehaviourLeadershipController::class)->riskUpdate($request, $flag, 'resolved'))->middleware('permission:resolve_attendance_risk_flags');
    Route::post('/{register}/correct', [AttendanceLeadershipController::class, 'correct'])->middleware('permission:correct_finalized_attendance');
    Route::get('/analytics', [AttendanceLeadershipController::class, 'analytics']);
    Route::get('/absentees', [AttendanceLeadershipController::class, 'absentees']);
    Route::get('/late', [AttendanceLeadershipController::class, 'absentees']);
    Route::get('/register-completion', [AttendanceLeadershipController::class, 'completion']);
    Route::get('/chronic-absence', [AttendanceLeadershipController::class, 'absentees']);
    Route::get('/', [AttendanceLeadershipController::class, 'index']);
    Route::get('/{register}', [AttendanceLeadershipController::class, 'show']);
});

Route::prefix('behaviour')->middleware($secure)->group(function () {
    Route::get('/', [BehaviourLeadershipController::class, 'index'])->middleware('permission:view_behaviour_analytics');
    Route::get('/analytics', [BehaviourLeadershipController::class, 'analytics'])->middleware('permission:view_behaviour_analytics');
    Route::get('/risk-indicators', [BehaviourLeadershipController::class, 'indicators'])->middleware('permission:view_behaviour_analytics');
    Route::get('/cases', [BehaviourLeadershipController::class, 'cases'])->middleware('permission:review_behaviour_cases');
    Route::get('/cases/{case}', [BehaviourLeadershipController::class, 'show'])->middleware('permission:review_behaviour_cases');
    Route::post('/cases/{case}/review', fn (Request $request, string $case) => app(BehaviourLeadershipController::class)->transition($request, $case, 'under_review'))->middleware('permission:review_behaviour_cases');
    Route::post('/cases/{case}/resolve', fn (Request $request, string $case) => app(BehaviourLeadershipController::class)->transition($request, $case, 'resolved'))->middleware('permission:resolve_behaviour_cases');
    Route::post('/cases/{case}/close', fn (Request $request, string $case) => app(BehaviourLeadershipController::class)->transition($request, $case, 'closed'))->middleware('permission:resolve_behaviour_cases');
    Route::post('/cases/{case}/reopen', fn (Request $request, string $case) => app(BehaviourLeadershipController::class)->transition($request, $case, 'reopened'))->middleware('permission:resolve_behaviour_cases');
    Route::post('/cases/{case}/actions', [BehaviourLeadershipController::class, 'action'])->middleware('permission:assign_restricted_behaviour_actions');
    Route::post('/recognitions/{recognition}/approve', [BehaviourLeadershipController::class, 'approve'])->middleware('permission:approve_behaviour_recognitions');
    Route::post('/referrals', [BehaviourLeadershipController::class, 'referral'])->middleware('permission:manage_counselling_referrals');
});

Route::prefix('attendance-statuses')
    ->middleware($secure)
    ->group(function () {

        Route::get('/', [AttendanceStatusController::class, 'index']);

        Route::get('/{id}', [AttendanceStatusController::class, 'show']);

        Route::post('/', [AttendanceStatusController::class, 'store']);

        Route::put('/{id}', [AttendanceStatusController::class, 'update']);

        Route::delete('/{id}', [AttendanceStatusController::class, 'destroy']);

    });

Route::prefix('attendance-sessions')
    ->middleware(['jwt', 'permission:manage_users'])
    ->group(function () {

        Route::get('/', [AttendanceSessionController::class, 'index']);

        Route::get('/{id}', [AttendanceSessionController::class, 'show']);

        Route::post('/', [AttendanceSessionController::class, 'store']);

        Route::put('/{id}', [AttendanceSessionController::class, 'update']);

        Route::delete('/{id}', [AttendanceSessionController::class, 'destroy']);

    });

Route::prefix('learner-attendance')
    ->middleware(['jwt', 'permission:manage_users'])
    ->group(function () {

        Route::get('/', [LearnerAttendanceController::class, 'index']);

        Route::get('/{id}', [LearnerAttendanceController::class, 'show']);

        // Legacy direct writes are intentionally disabled; use attendance registers.

    });

Route::prefix('attendance-alerts')
    ->middleware(['jwt', 'permission:manage_users'])
    ->group(function () {

        Route::get('/', [AttendanceAlertController::class, 'index']);

        Route::get('/{id}', [AttendanceAlertController::class, 'show']);

        // Alerts are system-generated only after register finalization.

    });

/*
|--------------------------------------------------------------------------
| Room Type Routes
|--------------------------------------------------------------------------
*/

Route::prefix('room-types')
    ->middleware($secure)
    ->group(function () {

        Route::get('/', [RoomTypeController::class, 'index']);

        Route::get('/{id}', [RoomTypeController::class, 'show']);

        Route::post('/', [RoomTypeController::class, 'store']);

        Route::put('/{id}', [RoomTypeController::class, 'update']);

        Route::delete('/{id}', [RoomTypeController::class, 'destroy']);

    });

/*
|--------------------------------------------------------------------------
| Room Routes
|--------------------------------------------------------------------------
*/

Route::prefix('rooms')
    ->middleware($secure)
    ->group(function () {

        Route::get('/', [RoomController::class, 'index']);

        Route::get('/{id}', [RoomController::class, 'show']);

        Route::post('/', [RoomController::class, 'store']);

        Route::put('/{id}', [RoomController::class, 'update']);

        Route::delete('/{id}', [RoomController::class, 'destroy']);

    });

/*
|--------------------------------------------------------------------------
| Timetable Routes
|--------------------------------------------------------------------------
*/

$middleware = $secure;

Route::prefix('timetable-profiles')
    ->middleware($middleware)
    ->group(function () {

        Route::get('/', [TimetableProfileController::class, 'index']);

        Route::get('/{id}', [TimetableProfileController::class, 'show']);

        Route::post('/', [TimetableProfileController::class, 'store']);

        Route::put('/{id}', [TimetableProfileController::class, 'update']);

        Route::delete('/{id}', [TimetableProfileController::class, 'destroy']);

    });

Route::prefix('timetable-periods')
    ->middleware($middleware)
    ->group(function () {

        Route::get('/', [TimetablePeriodController::class, 'index']);

        Route::get('/{id}', [TimetablePeriodController::class, 'show']);

        Route::post('/', [TimetablePeriodController::class, 'store']);

        Route::put('/{id}', [TimetablePeriodController::class, 'update']);

        Route::delete('/{id}', [TimetablePeriodController::class, 'destroy']);

    });

Route::prefix('timetable-entries')
    ->middleware($middleware)
    ->group(function () {

        Route::get('/', [TimetableEntryController::class, 'index']);

        Route::get('/{id}', [TimetableEntryController::class, 'show']);

        // Writes must use /timetables/{timetable}/entries so assignment scope is derived safely.

    });

Route::prefix('timetables')
    ->middleware($middleware)
    ->group(function () {
        Route::get('/', [SmartTimetableController::class, 'index'])->middleware('permission:view_school_timetable');
        Route::post('/', [SmartTimetableController::class, 'store'])->middleware('permission:create_timetable');
        Route::post('/{timetable}/generate', [SmartTimetableAutomationController::class, 'generate'])->middleware('permission:generate_timetable');
        Route::post('/{timetable}/repair', fn (Request $request, string $timetable) => app(SmartTimetableAutomationController::class)->repair($request, $timetable, 'repair'))->middleware('permission:repair_timetable');
        Route::post('/{timetable}/rebalance', fn (Request $request, string $timetable) => app(SmartTimetableAutomationController::class)->repair($request, $timetable, 'rebalance'))->middleware('permission:rebalance_timetable');
        Route::post('/{timetable}/regenerate-unallocated', fn (Request $request, string $timetable) => app(SmartTimetableAutomationController::class)->repair($request, $timetable, 'regenerate_unallocated'))->middleware('permission:repair_timetable');
        Route::post('/{timetable}/create-version', [SmartTimetableAutomationController::class, 'createVersion'])->middleware('permission:create_timetable_versions');
        Route::post('/{timetable}/entries/{entry}/lock', fn (Request $request, string $timetable, string $entry) => app(SmartTimetableAutomationController::class)->lock($request, $timetable, $entry, true))->middleware('permission:lock_timetable_entries');
        Route::post('/{timetable}/entries/{entry}/unlock', fn (Request $request, string $timetable, string $entry) => app(SmartTimetableAutomationController::class)->lock($request, $timetable, $entry, false))->middleware('permission:lock_timetable_entries');
        Route::get('/{timetable}/generation-runs', [SmartTimetableAutomationController::class, 'runs'])->middleware('permission:view_timetable_generation_runs');
        Route::get('/{timetable}/generation-runs/{run}', [SmartTimetableAutomationController::class, 'runs'])->middleware('permission:view_timetable_generation_runs');
        Route::post('/{timetable}/unpublish', [SmartTimetableAutomationController::class, 'unpublish'])->middleware('permission:unpublish_timetable');
        Route::post('/{timetable}/supersede', [SmartTimetableAutomationController::class, 'supersede'])->middleware('permission:supersede_timetable');
        Route::post('/{timetable}/validate', [SmartTimetableController::class, 'validateTimetable'])->middleware('permission:validate_timetable');
        Route::get('/{timetable}/conflicts', [SmartTimetableController::class, 'conflicts'])->middleware('permission:view_school_timetable');
        Route::get('/{timetable}/allocation-summary', [SmartTimetableController::class, 'conflicts'])->middleware('permission:view_timetable_analytics');
        Route::get('/{timetable}/grid', [SmartTimetableController::class, 'grid'])->middleware('permission:view_school_timetable');
        Route::post('/{timetable}/entries', [SmartTimetableController::class, 'entry'])->middleware('permission:edit_timetable');
        Route::put('/{timetable}/entries/{entry}', [SmartTimetableController::class, 'entry'])->middleware('permission:edit_timetable');
        Route::delete('/{timetable}/entries/{entry}', [SmartTimetableController::class, 'deleteEntry'])->middleware('permission:edit_timetable');
        Route::post('/{timetable}/approve', [SmartTimetableController::class, 'approve'])->middleware('permission:approve_timetable');
        Route::post('/{timetable}/publish', [SmartTimetableController::class, 'publish'])->middleware('permission:publish_timetable');
        Route::post('/{timetable}/archive', [SmartTimetableController::class, 'archive'])->middleware('permission:archive_timetable');
        Route::get('/{timetable}', [SmartTimetableController::class, 'show'])->middleware('permission:view_school_timetable');
        Route::put('/{timetable}', [SmartTimetableController::class, 'update'])->middleware('permission:edit_timetable');
    });

Route::prefix('timetable')->middleware($secure)->group(function () {
    Route::get('/substitutions/suggestions', [SmartTimetableAutomationController::class, 'suggestions'])->middleware('permission:manage_timetable_substitutions');
    Route::post('/substitutions', [SmartTimetableAutomationController::class, 'createSubstitution'])->middleware('permission:manage_timetable_substitutions');
    Route::get('/substitutions', [SmartTimetableAutomationController::class, 'substitutions'])->middleware('permission:manage_timetable_substitutions');
    Route::get('/substitutions/{substitution}', [SmartTimetableAutomationController::class, 'substitutions'])->middleware('permission:manage_timetable_substitutions');
    Route::post('/substitutions/{substitution}/approve', fn (Request $request, string $substitution) => app(SmartTimetableAutomationController::class)->substitutionAction($request, $substitution, 'approve'))->middleware('permission:approve_timetable_substitutions');
    Route::post('/substitutions/{substitution}/cancel', fn (Request $request, string $substitution) => app(SmartTimetableAutomationController::class)->substitutionAction($request, $substitution, 'cancel'))->middleware('permission:manage_timetable_substitutions');
    Route::get('/current-period', [SmartTimetableController::class, 'currentPeriod']);
    Route::get('/overview', [SmartTimetableController::class, 'analytics'])->middleware('permission:view_school_timetable');
    Route::get('/analytics', [SmartTimetableController::class, 'analytics'])->middleware('permission:view_timetable_analytics');
    Route::get('/teacher-workload', [SmartTimetableController::class, 'analytics'])->middleware('permission:view_timetable_analytics');
    Route::get('/room-utilization', [SmartTimetableController::class, 'analytics'])->middleware('permission:view_timetable_analytics');
    Route::get('/unallocated', [SmartTimetableController::class, 'analytics'])->middleware('permission:view_timetable_analytics');
    Route::get('/conflicts', [SmartTimetableController::class, 'analytics'])->middleware('permission:view_timetable_analytics');
});

Route::prefix('timetable-constraints')
    ->middleware($middleware)
    ->group(function () {

        Route::get('/', [TimetableConstraintController::class, 'index']);

        Route::get('/{id}', [TimetableConstraintController::class, 'show']);

        Route::post('/', [TimetableConstraintController::class, 'store']);

        Route::put('/{id}', [TimetableConstraintController::class, 'update']);

        Route::delete('/{id}', [TimetableConstraintController::class, 'destroy']);

    });

Route::prefix('teacher-constraints')
    ->middleware($middleware)
    ->group(function () {

        Route::get('/', [TeacherConstraintController::class, 'index']);

        Route::get('/{id}', [TeacherConstraintController::class, 'show']);

        Route::post('/', [TeacherConstraintController::class, 'store']);

        Route::put('/{id}', [TeacherConstraintController::class, 'update']);

        Route::delete('/{id}', [TeacherConstraintController::class, 'destroy']);

    });

Route::prefix('teacher-availability')
    ->middleware($middleware)
    ->group(function () {

        Route::get('/', [TeacherAvailabilityController::class, 'index']);

        Route::get('/{id}', [TeacherAvailabilityController::class, 'show']);

        Route::post('/', [TeacherAvailabilityController::class, 'store']);

        Route::put('/{id}', [TeacherAvailabilityController::class, 'update']);

        Route::delete('/{id}', [TeacherAvailabilityController::class, 'destroy']);

    });

Route::prefix('room-constraints')
    ->middleware($middleware)
    ->group(function () {

        Route::get('/', [RoomConstraintController::class, 'index']);

        Route::get('/{id}', [RoomConstraintController::class, 'show']);

        Route::post('/', [RoomConstraintController::class, 'store']);

        Route::put('/{id}', [RoomConstraintController::class, 'update']);

        Route::delete('/{id}', [RoomConstraintController::class, 'destroy']);

    });

Route::prefix('timetable-conflicts')
    ->middleware($middleware)
    ->group(function () {

        Route::get('/', [TimetableConflictController::class, 'index']);

        Route::get('/{id}', [TimetableConflictController::class, 'show']);

        // Conflicts are generated and resolved by timetable validation.

    });

Route::prefix('timetable-generation-runs')
    ->middleware($middleware)
    ->group(function () {

        Route::get('/', [TimetableGenerationRunController::class, 'index']);

        Route::get('/{id}', [TimetableGenerationRunController::class, 'show']);

        // Automatic generation belongs to Smart Timetable Phase 2.

    });

Route::prefix('timetable-publications')
    ->middleware($middleware)
    ->group(function () {

        Route::get('/', [TimetablePublicationController::class, 'index']);

        Route::get('/{id}', [TimetablePublicationController::class, 'show']);

        // Publication history is system-managed through the lifecycle service.

    });

Route::prefix('timetable-substitutions')
    ->middleware($middleware)
    ->group(function () {

        Route::get('/', [TimetableSubstitutionController::class, 'index']);

        Route::get('/{id}', [TimetableSubstitutionController::class, 'show']);

        // Phase 2 substitution writes use /timetable/substitutions and its approval workflow.

    });

/*
|--------------------------------------------------------------------------
| FINANCE MASTER DATA
|--------------------------------------------------------------------------
*/

Route::prefix('finance')->middleware($secure)->group(function () {
    Route::get('/discounts', [FinancePhaseTwoController::class, 'discounts'])->middleware('permission:manage_fee_discounts');
    Route::post('/discounts', [FinancePhaseTwoController::class, 'saveDiscount'])->middleware('permission:manage_fee_discounts');
    Route::get('/discounts/{discount}', [FinancePhaseTwoController::class, 'discount'])->middleware('permission:manage_fee_discounts');
    Route::put('/discounts/{discount}', [FinancePhaseTwoController::class, 'saveDiscount'])->middleware('permission:manage_fee_discounts');
    Route::post('/discounts/{discount}/approve', fn (string $discount) => app(FinancePhaseTwoController::class)->discountTransition($discount, 'approved'))->middleware('permission:approve_fee_discounts');
    Route::post('/discounts/{discount}/activate', fn (string $discount) => app(FinancePhaseTwoController::class)->discountTransition($discount, 'active'))->middleware('permission:approve_fee_discounts');
    Route::post('/discounts/{discount}/archive', fn (string $discount) => app(FinancePhaseTwoController::class)->discountTransition($discount, 'archived'))->middleware('permission:manage_fee_discounts');
    Route::get('/learner-discounts', [FinancePhaseTwoController::class, 'learnerDiscounts'])->middleware('permission:assign_learner_discounts');
    Route::post('/learner-discounts', [FinancePhaseTwoController::class, 'assignDiscount'])->middleware('permission:assign_learner_discounts');
    Route::get('/learner-discounts/{assignment}', [FinancePhaseTwoController::class, 'learnerDiscount'])->middleware('permission:assign_learner_discounts');
    Route::post('/learner-discounts/{assignment}/approve', fn (Request $request, string $assignment) => app(FinancePhaseTwoController::class)->learnerDiscountAction($request, $assignment, 'approve'))->middleware('permission:approve_learner_discounts');
    Route::post('/learner-discounts/{assignment}/cancel', fn (Request $request, string $assignment) => app(FinancePhaseTwoController::class)->learnerDiscountAction($request, $assignment, 'cancel'))->middleware('permission:assign_learner_discounts');
    Route::post('/invoices/{invoice}/apply-discounts', [FinancePhaseTwoController::class, 'applyDiscounts'])->middleware('permission:apply_fee_discounts');
    Route::get('/invoices/{invoice}/discounts', [FinancePhaseTwoController::class, 'invoiceDiscounts'])->middleware('permission:apply_fee_discounts');
    Route::post('/invoices/{invoice}/discounts/{application}/reverse', [FinancePhaseTwoController::class, 'reverseDiscount'])->middleware('permission:reverse_fee_discounts');
    Route::get('/payment-plans', [FinancePhaseTwoController::class, 'paymentPlans'])->middleware('permission:manage_payment_plans');
    Route::post('/payment-plans', [FinancePhaseTwoController::class, 'savePaymentPlan'])->middleware('permission:manage_payment_plans');
    Route::get('/payment-plans/{plan}', [FinancePhaseTwoController::class, 'paymentPlan'])->middleware('permission:manage_payment_plans');
    Route::put('/payment-plans/{plan}', [FinancePhaseTwoController::class, 'savePaymentPlan'])->middleware('permission:manage_payment_plans');
    Route::get('/payment-plans/{plan}/installments', [FinancePhaseTwoController::class, 'paymentPlan'])->middleware('permission:manage_payment_plans');
    Route::post('/payment-plans/{plan}/approve', fn (Request $request, string $plan) => app(FinancePhaseTwoController::class)->paymentPlanAction($request, $plan, 'approve'))->middleware('permission:approve_payment_plans');
    Route::post('/payment-plans/{plan}/activate', fn (Request $request, string $plan) => app(FinancePhaseTwoController::class)->paymentPlanAction($request, $plan, 'activate'))->middleware('permission:approve_payment_plans');
    Route::post('/payment-plans/{plan}/cancel', fn (Request $request, string $plan) => app(FinancePhaseTwoController::class)->paymentPlanAction($request, $plan, 'cancel'))->middleware('permission:manage_payment_plans');
    Route::post('/payment-plans/{plan}/reschedule', fn (Request $request, string $plan) => app(FinancePhaseTwoController::class)->paymentPlanAction($request, $plan, 'reschedule'))->middleware('permission:reschedule_payment_plans');
    Route::get('/refunds', [FinancePhaseTwoController::class, 'refunds'])->middleware('permission:request_fee_refunds');
    Route::post('/refunds', [FinancePhaseTwoController::class, 'requestRefund'])->middleware('permission:request_fee_refunds');
    Route::get('/refunds/{refund}', [FinancePhaseTwoController::class, 'refund'])->middleware('permission:request_fee_refunds');
    Route::post('/refunds/{refund}/approve', fn (Request $request, string $refund) => app(FinancePhaseTwoController::class)->refundAction($request, $refund, 'approve'))->middleware('permission:approve_fee_refunds');
    Route::post('/refunds/{refund}/reject', fn (Request $request, string $refund) => app(FinancePhaseTwoController::class)->refundAction($request, $refund, 'reject'))->middleware('permission:approve_fee_refunds');
    Route::post('/refunds/{refund}/process', fn (Request $request, string $refund) => app(FinancePhaseTwoController::class)->refundAction($request, $refund, 'process'))->middleware('permission:process_fee_refunds');
    Route::post('/refunds/{refund}/cancel', fn (Request $request, string $refund) => app(FinancePhaseTwoController::class)->refundAction($request, $refund, 'cancel'))->middleware('permission:request_fee_refunds');
    Route::get('/adjustments', [FinancePhaseTwoController::class, 'adjustments'])->middleware('permission:create_finance_adjustments');
    Route::post('/adjustments', [FinancePhaseTwoController::class, 'createAdjustment'])->middleware('permission:create_finance_adjustments');
    Route::get('/adjustments/{adjustment}', [FinancePhaseTwoController::class, 'adjustment'])->middleware('permission:create_finance_adjustments');
    Route::post('/adjustments/{adjustment}/submit', fn (Request $request, string $adjustment) => app(FinancePhaseTwoController::class)->adjustmentAction($request, $adjustment, 'submit'))->middleware('permission:create_finance_adjustments');
    Route::post('/adjustments/{adjustment}/approve', fn (Request $request, string $adjustment) => app(FinancePhaseTwoController::class)->adjustmentAction($request, $adjustment, 'approve'))->middleware('permission:approve_finance_adjustments');
    Route::post('/adjustments/{adjustment}/reject', fn (Request $request, string $adjustment) => app(FinancePhaseTwoController::class)->adjustmentAction($request, $adjustment, 'reject'))->middleware('permission:approve_finance_adjustments');
    Route::post('/adjustments/{adjustment}/post', fn (Request $request, string $adjustment) => app(FinancePhaseTwoController::class)->adjustmentAction($request, $adjustment, 'post'))->middleware('permission:post_finance_adjustments');
    Route::post('/adjustments/{adjustment}/reverse', fn (Request $request, string $adjustment) => app(FinancePhaseTwoController::class)->adjustmentAction($request, $adjustment, 'reverse'))->middleware('permission:reverse_finance_adjustments');
    Route::post('/arrears/calculate', [FinancePhaseTwoController::class, 'calculateArrears'])->middleware('permission:calculate_fee_arrears');
    Route::get('/arrears', [FinancePhaseTwoController::class, 'arrears'])->middleware('permission:calculate_fee_arrears');
    Route::get('/arrears/{arrear}', [FinancePhaseTwoController::class, 'arrear'])->middleware('permission:calculate_fee_arrears');
    Route::post('/arrears/{arrear}/carry-forward', fn (Request $request, string $arrear) => app(FinancePhaseTwoController::class)->arrearAction($request, $arrear, 'carry-forward'))->middleware('permission:carry_forward_fee_arrears');
    Route::post('/arrears/{arrear}/resolve', fn (Request $request, string $arrear) => app(FinancePhaseTwoController::class)->arrearAction($request, $arrear, 'resolve'))->middleware('permission:resolve_fee_arrears');
    Route::get('/accounts/{account}/statement', [FinancePhaseTwoController::class, 'statement'])->middleware('permission:export_finance_reports');
    Route::get('/learners/{learner}/clearance', [FinancePhaseTwoController::class, 'clearance'])->middleware('permission:view_fee_clearance');
    Route::post('/learners/{learner}/clearance/override', [FinancePhaseTwoController::class, 'clearanceOverride'])->middleware('permission:override_fee_clearance');
    Route::post('/learners/{learner}/clearance/revoke', [FinancePhaseTwoController::class, 'clearanceRevoke'])->middleware('permission:revoke_fee_clearance');
    Route::get('/clearance-certificates/{certificate}', [FinancePhaseTwoController::class, 'certificate'])->middleware('permission:view_fee_clearance');
    foreach (['discounts', 'payment-plans', 'refunds', 'arrears', 'clearance', 'aging', 'trends'] as $report) {
        Route::get('/analytics/'.$report, [FinancePhaseTwoController::class, 'analytics'])->middleware('permission:view_advanced_finance_analytics');
    }
    Route::get('/dashboard', [FinanceWorkflowController::class, 'analytics'])->middleware('permission:view_finance_analytics');
    Route::get('/analytics', [FinanceWorkflowController::class, 'analytics'])->middleware('permission:view_finance_analytics');
    Route::get('/collections', [FinanceWorkflowController::class, 'analytics'])->middleware('permission:view_finance_analytics');
    Route::get('/outstanding', [FinanceWorkflowController::class, 'analytics'])->middleware('permission:view_finance_analytics');
    Route::get('/invoice-summary', [FinanceWorkflowController::class, 'analytics'])->middleware('permission:view_finance_analytics');
    Route::get('/payment-summary', [FinanceWorkflowController::class, 'analytics'])->middleware('permission:view_finance_analytics');
    Route::get('/ledger-integrity', [FinanceWorkflowController::class, 'analytics'])->middleware('permission:reconcile_fee_ledger');
    Route::get('/settings', [FinanceWorkflowController::class, 'settings'])->middleware('permission:manage_finance_settings');
    Route::put('/settings', [FinanceWorkflowController::class, 'updateSettings'])->middleware('permission:manage_finance_settings');
    Route::get('/fee-categories', [FinanceWorkflowController::class, 'categories'])->middleware('permission:manage_fee_categories');
    Route::post('/fee-categories', [FinanceWorkflowController::class, 'saveCategory'])->middleware('permission:manage_fee_categories');
    Route::get('/fee-categories/{category}', [FinanceWorkflowController::class, 'category'])->middleware('permission:manage_fee_categories');
    Route::put('/fee-categories/{category}', [FinanceWorkflowController::class, 'saveCategory'])->middleware('permission:manage_fee_categories');
    Route::delete('/fee-categories/{category}', [FinanceWorkflowController::class, 'deactivateCategory'])->middleware('permission:manage_fee_categories');
    Route::get('/fee-structures', [FinanceWorkflowController::class, 'structures'])->middleware('permission:manage_fee_structures');
    Route::post('/fee-structures', [FinanceWorkflowController::class, 'saveStructure'])->middleware('permission:manage_fee_structures');
    Route::get('/fee-structures/{structure}', [FinanceWorkflowController::class, 'structure'])->middleware('permission:manage_fee_structures');
    Route::put('/fee-structures/{structure}', [FinanceWorkflowController::class, 'saveStructure'])->middleware('permission:manage_fee_structures');
    Route::post('/fee-structures/{structure}/approve', fn (string $structure) => app(FinanceWorkflowController::class)->structureTransition($structure, 'approved'))->middleware('permission:approve_fee_structures');
    Route::post('/fee-structures/{structure}/activate', fn (string $structure) => app(FinanceWorkflowController::class)->structureTransition($structure, 'active'))->middleware('permission:approve_fee_structures');
    Route::post('/fee-structures/{structure}/archive', fn (string $structure) => app(FinanceWorkflowController::class)->structureTransition($structure, 'archived'))->middleware('permission:manage_fee_structures');
    Route::get('/accounts', [FinanceWorkflowController::class, 'accounts'])->middleware('permission:provision_fee_accounts');
    Route::post('/accounts/provision', [FinanceWorkflowController::class, 'accountProvision'])->middleware('permission:provision_fee_accounts');
    Route::post('/accounts/provision-bulk', [FinanceWorkflowController::class, 'accountProvisionBulk'])->middleware('permission:provision_fee_accounts');
    Route::get('/accounts/{account}/ledger', [FinanceWorkflowController::class, 'ledger'])->middleware('permission:reconcile_fee_ledger');
    Route::post('/accounts/{account}/recalculate', [FinanceWorkflowController::class, 'recalculate'])->middleware('permission:reconcile_fee_ledger');
    Route::get('/accounts/{account}', [FinanceWorkflowController::class, 'account'])->middleware('permission:provision_fee_accounts');
    Route::post('/invoices/generate', [FinanceWorkflowController::class, 'generateInvoice'])->middleware('permission:generate_fee_invoices');
    Route::post('/invoices/generate-bulk', [FinanceWorkflowController::class, 'generateInvoicesBulk'])->middleware('permission:generate_fee_invoices');
    Route::get('/invoices', [FinanceWorkflowController::class, 'invoices'])->middleware('permission:generate_fee_invoices');
    Route::post('/invoices/{invoice}/post', [FinanceWorkflowController::class, 'postInvoice'])->middleware('permission:post_fee_invoices');
    Route::post('/invoices/{invoice}/cancel', [FinanceWorkflowController::class, 'cancelInvoice'])->middleware('permission:cancel_fee_invoices');
    Route::get('/invoices/{invoice}', [FinanceWorkflowController::class, 'invoice'])->middleware('permission:generate_fee_invoices');
    Route::get('/payments', [FinanceWorkflowController::class, 'payments'])->middleware('permission:record_fee_payments');
    Route::get('/payment-methods', [FinanceWorkflowController::class, 'paymentMethods'])->middleware('permission:record_fee_payments');
    Route::post('/payments', [FinanceWorkflowController::class, 'recordPayment'])->middleware('permission:record_fee_payments');
    Route::post('/payments/{payment}/confirm', [FinanceWorkflowController::class, 'confirmPayment'])->middleware('permission:confirm_fee_payments');
    Route::post('/payments/{payment}/reverse', [FinanceWorkflowController::class, 'reversePayment'])->middleware('permission:reverse_fee_payments');
    Route::post('/payments/{payment}/allocate', [FinanceWorkflowController::class, 'allocate'])->middleware('permission:allocate_fee_payments');
    Route::post('/payments/{payment}/auto-allocate', [FinanceWorkflowController::class, 'autoAllocate'])->middleware('permission:allocate_fee_payments');
    Route::get('/payments/{payment}/allocations', [FinanceWorkflowController::class, 'allocations'])->middleware('permission:allocate_fee_payments');
    Route::get('/payments/{payment}/receipt', [FinanceWorkflowController::class, 'receipt'])->middleware('permission:view_finance_receipts');
    Route::get('/payments/{payment}', [FinanceWorkflowController::class, 'payment'])->middleware('permission:record_fee_payments');
    Route::get('/receipts/{receipt}', [FinanceWorkflowController::class, 'receiptNumber'])->middleware('permission:view_finance_receipts');
});

Route::middleware($secure)->group(function () {

    Route::apiResource(

        'fee-categories',

        FeeCategoryController::class

    )->only(['index', 'show']);

    Route::apiResource(

        'payment-plans',

        PaymentPlanController::class

    )->only(['index', 'show']);

    Route::apiResource(

        'payment-methods',

        PaymentMethodController::class

    )->only(['index', 'show']);

    Route::apiResource(

        'finance-settings',

        FinanceSettingController::class

    )->only(['index', 'show']);
    Route::apiResource(

        'fee-structures',

        FeeStructureController::class

    )->only(['index', 'show']);
    Route::apiResource(

        'fee-invoices',

        FeeInvoiceController::class

    )->only(['index', 'show']);
    Route::apiResource(

        'payments',

        PaymentController::class

    )->only(['index', 'show']);
    Route::apiResource(

        'payment-allocations',

        PaymentAllocationController::class

    )->only(['index', 'show']);

    Route::apiResource(

        'learner-fee-accounts',

        LearnerFeeAccountController::class

    )->only(['index', 'show']);

});
