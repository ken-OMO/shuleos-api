<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exam_rosters', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools');
            $table->foreignUuid('exam_id')->unique()->constrained('exams');
            $table->foreignUuid('created_by')->constrained('users');
            $table->foreignUuid('updated_by')->constrained('users');
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();

            $table->unique(['id', 'school_id', 'exam_id'], 'exam_rosters_identity_unique');
        });

        Schema::create('exam_roster_learners', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('roster_id');
            $table->uuid('school_id');
            $table->uuid('exam_id');
            $table->foreignUuid('learner_id')->constrained('learners');
            $table->foreignUuid('grade_id')->constrained('grades');
            $table->foreignUuid('stream_id')->nullable()->constrained('streams');
            $table->timestamps();

            $table->foreign(['roster_id', 'school_id', 'exam_id'], 'exam_roster_learners_roster_fk')
                ->references(['id', 'school_id', 'exam_id'])
                ->on('exam_rosters')
                ->cascadeOnDelete();

            $table->unique(['roster_id', 'learner_id'], 'exam_roster_learners_learner_unique');
            $table->index(['school_id', 'exam_id', 'grade_id'], 'exam_roster_learners_grade_idx');
            $table->index(['school_id', 'exam_id', 'stream_id'], 'exam_roster_learners_stream_idx');
        });

        Schema::create('exam_roster_papers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('roster_learner_id')
                ->constrained('exam_roster_learners')
                ->cascadeOnDelete();
            $table->foreignUuid('exam_learning_area_id')->constrained('exam_learning_areas');
            $table->foreignUuid('learning_area_id')->constrained('learning_areas');
            $table->foreignUuid('paper_id')->constrained('exam_papers');

            // Snapshot the configuration used to establish expected coverage.
            $table->unsignedInteger('paper_number');
            $table->decimal('maximum_marks', 8, 2);
            $table->unsignedInteger('expected_paper_count');
            $table->decimal('learning_area_total_marks', 8, 2);
            $table->timestamps();

            $table->unique(['roster_learner_id', 'paper_id'], 'exam_roster_papers_paper_unique');
            $table->index(
                ['roster_learner_id', 'learning_area_id'],
                'exam_roster_papers_area_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exam_roster_papers');
        Schema::dropIfExists('exam_roster_learners');
        Schema::dropIfExists('exam_rosters');
    }
};
