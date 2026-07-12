<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leadership_dashboard_preferences', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('school_id')->constrained('schools');
            $t->foreignUuid('user_id')->unique()->constrained('users');
            foreach (['attendance', 'teacher_attendance', 'curriculum_coverage', 'pending_approvals', 'lesson_plans', 'records_of_work', 'exams', 'report_cards', 'academic_performance', 'discipline', 'finance', 'announcements', 'notifications', 'teacher_workload', 'learner_enrolment'] as $f) {
                $t->boolean('show_'.$f)->default(true);
            }$t->timestamps();
            $t->index(['school_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leadership_dashboard_preferences');
    }
};
