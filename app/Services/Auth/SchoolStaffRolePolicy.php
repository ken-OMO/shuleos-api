<?php

namespace App\Services\Auth;

final class SchoolStaffRolePolicy
{
    public const OFFICE = ['access_school_office', 'view_school_office_summary'];

    public const HEADTEACHER = [
        'manage_academics',
        'access_school_leadership_portal', 'view_leadership_dashboard',
        'view_school_attendance_summary', 'view_school_curriculum_summary', 'view_school_academic_summary',
        'view_school_discipline_summary', 'view_leadership_approvals', 'manage_leadership_approvals',
        'manage_leadership_dashboard_preferences', 'view_hod_department_analytics', 'review_teacher_duty_reports',
        'access_leadership_portal_phase_two', 'view_principal_dashboard', 'view_school_kpis',
        'view_teacher_compliance', 'view_teacher_workload', 'view_academic_insights',
        'view_attendance_intelligence', 'view_behaviour_oversight', 'view_communication_monitoring',
        'view_timetable_oversight', 'view_leadership_action_queue', 'view_leadership_alerts',
        'acknowledge_leadership_alerts', 'manage_leadership_preferences', 'manage_leadership_devices',
        'view_leadership_reports', 'generate_leadership_reports', 'review_cross_module_approvals',
    ];

    public static function allows(string $role, string $permission): bool
    {
        $role = strtolower(trim($role));
        if ($role === 'school secretary') {
            return in_array($permission, self::OFFICE, true);
        }
        // Finance requires a separate assigned role; old leadership grants cannot confer it.
        if (in_array($role, ['headteacher', 'principal'], true)) {
            return in_array($permission, self::HEADTEACHER, true);
        }

        return true;
    }
}
