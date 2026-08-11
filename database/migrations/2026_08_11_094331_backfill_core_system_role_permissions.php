<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        /*
        |--------------------------------------------------------------------------
        | Teacher
        |--------------------------------------------------------------------------
        */

        $this->grant('Teacher', [
            'access_teacher_portal',
            'view_teacher_dashboard',
            'view_teacher_classes',
            'view_teacher_learners',
            'view_teacher_analytics',
        ]);

        /*
        |--------------------------------------------------------------------------
        | Parent
        |--------------------------------------------------------------------------
        */

        $this->grant('Parent', [
            'access_parent_portal',
            'view_linked_learners',
            'view_parent_report_cards',
            'download_parent_report_cards',
            'view_parent_attendance',
            'view_parent_fees',
            'view_parent_announcements',
        ]);

        /*
        |--------------------------------------------------------------------------
        | Learner
        |--------------------------------------------------------------------------
        */

        $this->grant('Learner', [
            'access_learner_portal',
            'view_learner_dashboard',
            'view_own_timetable',
            'view_own_attendance',
            'view_own_results',
            'view_own_report_cards',
            'download_own_report_cards',
            'view_own_fees',
            'view_learner_announcements',
            'manage_learner_dashboard_preferences',
        ]);

        /*
        |--------------------------------------------------------------------------
        | Learner account administration
        |--------------------------------------------------------------------------
        */

        foreach ([
            'School Admin',
            'Administrator',
            'Principal',
        ] as $role) {
            $this->grant($role, [
                'manage_learner_portal_accounts',
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | Parent portal administration
        |--------------------------------------------------------------------------
        */

        foreach ([
            'School Admin',
            'Administrator',
            'Principal',
        ] as $role) {
            $this->grant($role, [
                'manage_report_card_access_policy',
                'manage_report_card_access_overrides',
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | Administrator Portal
        |--------------------------------------------------------------------------
        */

        $allAdmin = [
            'access_administrator_portal',
            'access_platform_administration',
            'view_admin_dashboard',
            'view_platform_dashboard',
            'manage_school_profile',
            'view_school_completeness',
            'manage_school_lifecycle',
            'view_school_users',
            'create_school_users',
            'update_school_users',
            'activate_school_users',
            'suspend_school_users',
            'unlock_school_users',
            'force_school_user_password_reset',
            'revoke_school_user_sessions',
            'revoke_school_user_devices',
            'view_roles_and_permissions',
            'manage_school_roles',
            'assign_school_permissions',
            'view_academic_setup_status',
            'manage_school_branding',
            'view_school_subscription',
            'view_platform_subscriptions',
            'view_module_readiness',
            'view_admin_audit',
            'view_admin_security',
            'manage_admin_security_actions',
            'view_school_devices',
            'revoke_school_devices',
            'manage_communication_policy',
            'view_provider_readiness',
            'view_payment_reconciliation_summary',
            'manage_data_imports',
            'view_system_health',
            'view_admin_tasks',
            'view_admin_alerts',
            'acknowledge_admin_alerts',
            'manage_admin_preferences',
            'view_admin_reports',
            'generate_admin_reports',
        ];

        $platformOnly = [
            'access_platform_administration',
            'view_platform_dashboard',
            'manage_school_lifecycle',
            'view_platform_subscriptions',
        ];

        $schoolAdmin = array_values(
            array_diff($allAdmin, $platformOnly)
        );

        $readOnly = [
            'access_administrator_portal',
            'view_admin_dashboard',
            'view_school_completeness',
            'view_school_users',
            'view_roles_and_permissions',
            'view_academic_setup_status',
            'view_school_subscription',
            'view_module_readiness',
            'view_admin_audit',
            'view_admin_security',
            'view_school_devices',
            'view_provider_readiness',
            'view_system_health',
            'view_admin_tasks',
            'view_admin_alerts',
            'manage_admin_preferences',
            'view_admin_reports',
        ];

        $finance = [
            'access_administrator_portal',
            'view_admin_dashboard',
            'view_school_subscription',
            'view_provider_readiness',
            'view_payment_reconciliation_summary',
            'view_admin_tasks',
            'view_admin_alerts',
            'view_admin_reports',
        ];

        $this->grant(
            'Platform Owner',
            $allAdmin
        );

        $this->grant(
            'Platform Super Administrator',
            $allAdmin
        );

        $this->grant(
            'School Admin',
            $schoolAdmin
        );

        $this->grant(
            'Administrator',
            $schoolAdmin
        );

        $this->grant(
            'Principal',
            array_values(array_unique(array_merge(
                $readOnly,
                [
                    'manage_school_profile',
                    'update_school_users',
                    'activate_school_users',
                    'suspend_school_users',
                    'unlock_school_users',
                    'revoke_school_user_sessions',
                    'revoke_school_user_devices',
                    'manage_school_branding',
                    'manage_communication_policy',
                ]
            )))
        );

        $this->grant(
            'Headteacher',
            $readOnly
        );

        $this->grant(
            'Support Administrator',
            $readOnly
        );

        $this->grant(
            'Finance Administrator',
            $finance
        );

        $this->grant(
            'Finance Officer',
            $finance
        );

        /*
        |--------------------------------------------------------------------------
        | Administrator Operations
        |--------------------------------------------------------------------------
        */

        $operations = [
            'access_administrator_operations',
            'manage_school_feature_flags',
            'manage_platform_feature_flags',
            'manage_school_maintenance',
            'manage_platform_maintenance',
            'view_provider_configuration',
            'manage_provider_configuration',
            'rotate_provider_secrets',
            'view_queue_operations',
            'retry_failed_jobs',
            'forget_failed_jobs',
            'view_scheduler_operations',
            'run_allowlisted_scheduler_tasks',
            'view_cache_operations',
            'clear_safe_cache_groups',
            'view_application_logs',
            'view_storage_operations',
            'manage_quarantined_files',
            'view_backup_operations',
            'create_backups',
            'verify_backups',
            'archive_backups',
            'view_restore_operations',
            'create_restore_requests',
            'execute_restore_operations',
            'manage_api_keys',
            'manage_webhooks',
            'view_operational_diagnostics',
            'run_operational_diagnostics',
            'manage_system_notices',
            'view_release_metadata',
            'manage_platform_settings',
            'view_disaster_recovery_readiness',
        ];

        $platformOperations = array_values(array_diff(
            $operations,
            ['execute_restore_operations']
        ));

        $schoolOperations = [
            'access_administrator_operations',
            'manage_school_feature_flags',
            'manage_school_maintenance',
            'view_provider_configuration',
            'view_cache_operations',
            'clear_safe_cache_groups',
            'view_storage_operations',
            'view_backup_operations',
            'create_backups',
            'verify_backups',
            'archive_backups',
            'manage_api_keys',
            'manage_webhooks',
            'view_operational_diagnostics',
            'run_operational_diagnostics',
            'view_release_metadata',
        ];

        $this->grant(
            'Platform Owner',
            $operations
        );

        $this->grant(
            'Platform Super Administrator',
            $platformOperations
        );

        $this->grant(
            'School Admin',
            $schoolOperations
        );

        $this->grant(
            'Administrator',
            $schoolOperations
        );
    }

    private function grant(
        string $roleName,
        array $permissionNames
    ): void {
        $roleId = DB::table('roles')
            ->where('role_name', $roleName)
            ->whereNull('school_id')
            ->where('system_role', true)
            ->where('active', true)
            ->value('id');

        if (! $roleId) {
            throw new \RuntimeException(
                "Required system role [{$roleName}] does not exist."
            );
        }

        $permissionIds = DB::table('permissions')
            ->whereIn('permission_name', $permissionNames)
            ->pluck('id', 'permission_name');

        $missing = array_values(array_diff(
            $permissionNames,
            $permissionIds->keys()->all()
        ));

        if ($missing !== []) {
            throw new \RuntimeException(
                'Missing permissions for role [' .
                $roleName .
                ']: ' .
                implode(', ', $missing)
            );
        }

        foreach ($permissionIds as $permissionId) {
            $exists = DB::table('role_permissions')
                ->where('role_id', $roleId)
                ->where('permission_id', $permissionId)
                ->exists();

            if ($exists) {
                continue;
            }

            DB::table('role_permissions')->insert([
                'id' => (string) Str::uuid(),
                'role_id' => $roleId,
                'permission_id' => $permissionId,
                'created_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        /*
         * Intentionally non-destructive.
         *
         * System role permission assignments are security-critical
         * application baseline data and may already be referenced
         * by active users.
         */
    }
};

