<?php

namespace App\Models;

class LearnerDashboardPreference extends TenantModel
{
    protected $table = 'learner_dashboard_preferences';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $casts = [
        'show_timetable' => 'boolean', 'show_attendance' => 'boolean', 'show_results' => 'boolean',
        'show_report_cards' => 'boolean', 'show_fees' => 'boolean', 'show_announcements' => 'boolean',
        'show_notifications' => 'boolean', 'show_upcoming_exams' => 'boolean', 'show_learning_resources' => 'boolean',
        'dashboard_widgets' => 'array', 'notification_preferences' => 'array',
        'accessibility_preferences' => 'array', 'last_synced_at' => 'datetime',
    ];

    public function learner()
    {
        return $this->belongsTo(Learner::class);
    }
}
