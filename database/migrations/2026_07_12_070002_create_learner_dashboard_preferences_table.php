<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('learner_dashboard_preferences', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('school_id')->constrained('schools');
            $t->foreignUuid('learner_id')->unique()->constrained('learners');
            foreach (['timetable', 'attendance', 'results', 'report_cards', 'fees', 'announcements', 'notifications', 'upcoming_exams', 'learning_resources'] as $f) {
                $t->boolean('show_'.$f)->default($f !== 'fees');
            }$t->timestamps();
            $t->index(['school_id', 'learner_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('learner_dashboard_preferences');
    }
};
