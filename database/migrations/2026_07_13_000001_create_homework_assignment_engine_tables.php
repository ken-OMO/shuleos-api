<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('homework_assignments', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('school_id')->constrained('schools');
            $t->foreignUuid('teacher_assignment_id')->constrained('teacher_assignments');
            $t->foreignUuid('teacher_id')->constrained('teachers');
            $t->foreignUuid('learning_area_id')->constrained('learning_areas');
            $t->foreignUuid('grade_id')->constrained('grades');
            $t->foreignUuid('stream_id')->nullable()->constrained('streams');
            $t->foreignUuid('academic_year_id')->constrained('academic_years');
            $t->foreignUuid('term_id')->constrained('terms');
            $t->foreignUuid('scheme_lesson_id')->nullable()->constrained('scheme_lessons');
            $t->foreignUuid('lesson_plan_id')->nullable()->constrained('lesson_plans');
            $t->string('title');
            $t->text('instructions');
            $t->string('assignment_type');
            $t->string('submission_mode');
            $t->decimal('total_marks', 10, 2)->nullable();
            $t->string('grading_mode');
            $t->timestamp('publish_at')->nullable();
            $t->timestamp('published_at')->nullable();
            $t->timestamp('due_at');
            $t->boolean('allow_late_submission')->default(true);
            $t->string('late_penalty_type')->nullable();
            $t->decimal('late_penalty_value', 10, 2)->nullable();
            $t->unsignedInteger('maximum_attempts')->default(1);
            $t->boolean('allow_resubmission')->default(false);
            $t->boolean('show_marks_immediately')->default(false);
            $t->boolean('show_feedback_immediately')->default(false);
            $t->string('status')->default('draft');
            $t->foreignUuid('created_by')->constrained('users');
            $t->foreignUuid('updated_by')->nullable()->constrained('users');
            $t->timestamps();
            $t->boolean('is_deleted')->default(false);
            $t->timestamp('deleted_at')->nullable();
            $t->foreignUuid('deleted_by')->nullable()->constrained('users');
            $t->index(['school_id', 'status', 'grade_id', 'stream_id']);
        });
        Schema::create('homework_assignment_resources', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('school_id')->constrained('schools');
            $t->foreignUuid('assignment_id')->constrained('homework_assignments');
            $t->foreignUuid('learning_resource_id')->constrained('learning_resources');
            $t->unsignedInteger('display_order')->default(0);
            $t->boolean('required')->default(false);
            $t->timestamp('created_at');
            $t->unique(['assignment_id', 'learning_resource_id']);
        });
        Schema::create('homework_rubrics', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('school_id')->constrained('schools');
            $t->foreignUuid('assignment_id')->unique()->constrained('homework_assignments');
            $t->string('title');
            $t->text('description')->nullable();
            $t->decimal('total_points', 10, 2)->nullable();
            $t->foreignUuid('created_by')->constrained('users');
            $t->timestamps();
        });
        Schema::create('homework_rubric_criteria', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('school_id')->constrained('schools');
            $t->foreignUuid('rubric_id')->constrained('homework_rubrics');
            $t->string('criterion');
            $t->text('description')->nullable();
            $t->decimal('maximum_points', 10, 2)->nullable();
            $t->unsignedInteger('display_order')->default(0);
            $t->timestamps();
        });
        Schema::create('homework_rubric_levels', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('school_id')->constrained('schools');
            $t->foreignUuid('criterion_id')->constrained('homework_rubric_criteria');
            $t->string('level_name');
            $t->text('description')->nullable();
            $t->decimal('points', 10, 2)->nullable();
            $t->string('competency_code')->nullable();
            $t->unsignedInteger('display_order')->default(0);
            $t->timestamps();
        });
        Schema::create('homework_assignment_learners', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('school_id')->constrained('schools');
            $t->foreignUuid('assignment_id')->constrained('homework_assignments');
            $t->foreignUuid('learner_id')->constrained('learners');
            $t->timestamp('assigned_at');
            $t->string('availability_status')->default('available');
            $t->text('exemption_reason')->nullable();
            $t->timestamp('first_viewed_at')->nullable();
            $t->timestamp('last_viewed_at')->nullable();
            $t->string('submission_status')->default('not_started');
            $t->timestamps();
            $t->unique(['assignment_id', 'learner_id']);
        });
        Schema::create('homework_submissions', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('school_id')->constrained('schools');
            $t->foreignUuid('assignment_id')->constrained('homework_assignments');
            $t->foreignUuid('assignment_learner_id')->constrained('homework_assignment_learners');
            $t->foreignUuid('learner_id')->constrained('learners');
            $t->unsignedInteger('attempt_number');
            $t->text('text_response')->nullable();
            $t->text('external_url')->nullable();
            $t->timestamp('submitted_at')->nullable();
            $t->boolean('is_late')->default(false);
            $t->unsignedInteger('lateness_minutes')->nullable();
            $t->string('submission_status')->default('draft');
            $t->text('learner_comment')->nullable();
            $t->timestamps();
            $t->unique(['assignment_learner_id', 'attempt_number']);
        });
        Schema::create('homework_submission_files', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('school_id')->constrained('schools');
            $t->foreignUuid('submission_id')->constrained('homework_submissions');
            $t->string('storage_id');
            $t->string('original_filename');
            $t->string('safe_download_filename');
            $t->string('mime_type');
            $t->string('extension');
            $t->unsignedBigInteger('source_size');
            $t->unsignedBigInteger('stored_size');
            $t->string('source_hash');
            $t->string('stored_hash');
            $t->boolean('encrypted')->default(true);
            $t->timestamp('uploaded_at');
            $t->foreignUuid('created_by')->constrained('users');
        });
        Schema::create('homework_submission_marks', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('school_id')->constrained('schools');
            $t->foreignUuid('submission_id')->unique()->constrained('homework_submissions');
            $t->foreignUuid('assignment_id')->constrained('homework_assignments');
            $t->foreignUuid('learner_id')->constrained('learners');
            $t->decimal('raw_score', 10, 2)->nullable();
            $t->decimal('percentage', 7, 2)->nullable();
            $t->string('competency_level')->nullable();
            $t->text('teacher_feedback')->nullable();
            $t->text('private_teacher_notes')->nullable();
            $t->foreignUuid('marked_by')->constrained('users');
            $t->timestamp('marked_at')->nullable();
            $t->timestamp('released_at')->nullable();
            $t->string('status')->default('draft');
            $t->decimal('late_penalty_applied', 10, 2)->nullable();
            $t->decimal('final_score', 10, 2)->nullable();
            $t->timestamps();
        });
        Schema::create('homework_submission_rubric_scores', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('school_id')->constrained('schools');
            $t->foreignUuid('submission_mark_id')->constrained('homework_submission_marks');
            $t->foreignUuid('criterion_id')->constrained('homework_rubric_criteria');
            $t->foreignUuid('level_id')->nullable()->constrained('homework_rubric_levels');
            $t->decimal('points_awarded', 10, 2)->nullable();
            $t->text('comment')->nullable();
            $t->timestamps();
            $t->unique(['submission_mark_id', 'criterion_id']);
        });
        Schema::create('homework_assignment_audit_logs', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('school_id')->constrained('schools');
            $t->foreignUuid('assignment_id')->nullable()->constrained('homework_assignments');
            $t->foreignUuid('submission_id')->nullable()->constrained('homework_submissions');
            $t->foreignUuid('actor_user_id')->nullable()->constrained('users');
            $t->string('action');
            $t->jsonb('metadata')->nullable();
            $t->timestamp('created_at');
            $t->index(['school_id', 'assignment_id', 'action']);
        });
    }

    public function down(): void
    {
        foreach (['homework_assignment_audit_logs', 'homework_submission_rubric_scores', 'homework_submission_marks', 'homework_submission_files', 'homework_submissions', 'homework_assignment_learners', 'homework_rubric_levels', 'homework_rubric_criteria', 'homework_rubrics', 'homework_assignment_resources', 'homework_assignments'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
