<?php

namespace App\Models;

class LeadershipDashboardPreference extends TenantModel
{
    protected $table = 'leadership_dashboard_preferences';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $casts = [
        'show_attendance' => 'boolean',
        'show_teacher_attendance' => 'boolean',
        'show_curriculum_coverage' => 'boolean',
        'show_pending_approvals' => 'boolean',
        'show_lesson_plans' => 'boolean',
        'show_records_of_work' => 'boolean',
        'show_exams' => 'boolean',
        'show_report_cards' => 'boolean',
        'show_academic_performance' => 'boolean',
        'show_discipline' => 'boolean',
        'show_finance' => 'boolean',
        'show_announcements' => 'boolean',
        'show_notifications' => 'boolean',
        'show_teacher_workload' => 'boolean',
        'show_learner_enrolment' => 'boolean',
        'dashboard_widgets' => 'array',
        'notification_preferences' => 'array',
        'kpi_widget_order' => 'array',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
