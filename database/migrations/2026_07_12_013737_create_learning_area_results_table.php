<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('learning_area_results', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->uuid('school_id');
            $table->uuid('exam_id');
            $table->uuid('learner_id');
            $table->uuid('learning_area_id');

            $table->decimal('marks_obtained', 8, 2);
            $table->decimal('maximum_marks', 8, 2);
            $table->decimal('percentage', 6, 2);

            $table->uuid('grading_system_id');
            $table->uuid('grading_scale_id');

            $table->string('processing_status', 30)->default('processed');
            $table->uuid('processed_by')->nullable();
            $table->timestamp('processed_at');

            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();

            $table->boolean('is_deleted')->default(false);
            $table->timestamp('deleted_at')->nullable();
            $table->uuid('deleted_by')->nullable();

            $table->foreign('school_id')
                ->references('id')
                ->on('schools');

            $table->foreign('exam_id')
                ->references('id')
                ->on('exams');

            $table->foreign('learner_id')
                ->references('id')
                ->on('learners');

            $table->foreign('learning_area_id')
                ->references('id')
                ->on('learning_areas');

            $table->foreign('grading_system_id')
                ->references('id')
                ->on('grading_systems');

            $table->foreign('grading_scale_id')
                ->references('id')
                ->on('grading_scales');

            $table->foreign('processed_by')
                ->references('id')
                ->on('users');

            $table->foreign('deleted_by')
                ->references('id')
                ->on('users');

            $table->unique(
                ['school_id', 'exam_id', 'learner_id', 'learning_area_id'],
                'learning_area_results_unique'
            );

            $table->index(
                ['school_id', 'exam_id'],
                'learning_area_results_school_exam_idx'
            );

            $table->index(
                ['exam_id', 'learning_area_id'],
                'learning_area_results_exam_area_idx'
            );

            $table->index(
                ['exam_id', 'learner_id'],
                'learning_area_results_exam_learner_idx'
            );

            $table->index(
                ['school_id', 'processing_status'],
                'learning_area_results_status_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('learning_area_results');
    }
};
