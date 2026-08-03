<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('homework_submission_mark_revisions', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('school_id')->constrained('schools');
            $t->foreignUuid('submission_mark_id')->constrained('homework_submission_marks');
            $t->foreignUuid('assignment_id')->constrained('homework_assignments');
            $t->foreignUuid('submission_id')->constrained('homework_submissions');
            $t->foreignUuid('learner_id')->constrained('learners');
            $t->decimal('previous_raw_score', 10, 2)->nullable();
            $t->decimal('new_raw_score', 10, 2)->nullable();
            $t->decimal('previous_final_score', 10, 2)->nullable();
            $t->decimal('new_final_score', 10, 2)->nullable();
            $t->string('previous_competency_level')->nullable();
            $t->string('new_competency_level')->nullable();
            $t->text('previous_feedback')->nullable();
            $t->text('new_feedback')->nullable();
            $t->text('revision_reason');
            $t->foreignUuid('revised_by')->constrained('users');
            $t->timestamp('revised_at');
            $t->index(['school_id', 'assignment_id']);
        });
        Schema::create('homework_notification_events', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('school_id')->constrained('schools');
            $t->foreignUuid('assignment_id')->constrained('homework_assignments');
            $t->foreignUuid('assignment_learner_id')->nullable()->constrained('homework_assignment_learners');
            $t->foreignUuid('user_id')->constrained('users');
            $t->string('event_key');
            $t->timestamp('created_at');
            $t->unique(['user_id', 'event_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('homework_notification_events');
        Schema::dropIfExists('homework_submission_mark_revisions');
    }
};
