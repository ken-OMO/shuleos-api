<?php

namespace App\Models;

class TeacherDashboardPreference extends TenantModel
{
    protected $table = 'teacher_dashboard_preferences';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['id', 'school_id', 'teacher_id', 'show_todays_timetable', 'show_pending_lesson_plans', 'show_curriculum_coverage', 'show_notifications', 'show_announcements', 'show_attendance_summary', 'show_assessment_summary', 'show_performance_analytics', 'preferred_language', 'timezone', 'default_assignment_id', 'default_stream_id', 'timetable_view'];

    protected $casts = ['show_todays_timetable' => 'boolean', 'show_pending_lesson_plans' => 'boolean', 'show_curriculum_coverage' => 'boolean', 'show_notifications' => 'boolean', 'show_announcements' => 'boolean', 'show_attendance_summary' => 'boolean', 'show_assessment_summary' => 'boolean', 'show_performance_analytics' => 'boolean'];

    public function teacher()
    {
        return $this->belongsTo(Teacher::class);
    }
}
