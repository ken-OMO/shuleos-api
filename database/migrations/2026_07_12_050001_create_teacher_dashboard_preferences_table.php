<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('teacher_dashboard_preferences', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('school_id')->constrained('schools');
            $t->foreignUuid('teacher_id')->unique()->constrained('teachers');
            foreach (['show_todays_timetable', 'show_pending_lesson_plans', 'show_curriculum_coverage', 'show_notifications', 'show_announcements', 'show_attendance_summary', 'show_assessment_summary', 'show_performance_analytics'] as $f) {
                $t->boolean($f)->default(true);
            }$t->timestamps();
            $t->index(['school_id', 'teacher_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('teacher_dashboard_preferences');
    }
};
