<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('merit_lists', function (Blueprint $table) {
            $table->decimal('maximum_marks', 10, 2)
                ->nullable()
                ->after('total_score');

            $table->decimal('average_percentage', 6, 2)
                ->nullable()
                ->after('maximum_marks');

            $table->uuid('overall_grading_system_id')
                ->nullable()
                ->after('total_points');

            $table->uuid('overall_grading_scale_id')
                ->nullable()
                ->after('overall_grading_system_id');

            $table->string('ranking_method', 30)
                ->default('competition')
                ->after('school_position');

            $table->string('status', 30)
                ->default('generated')
                ->after('ranking_method');

            $table->uuid('generated_by')
                ->nullable()
                ->after('status');

            $table->timestamp('generated_at')
                ->nullable()
                ->after('generated_by');

            $table->timestamp('published_at')
                ->nullable()
                ->after('generated_at');

            $table->timestamp('updated_at')
                ->nullable()
                ->after('created_at');

            $table->boolean('is_deleted')
                ->default(false)
                ->after('updated_at');

            $table->timestamp('deleted_at')
                ->nullable()
                ->after('is_deleted');

            $table->uuid('deleted_by')
                ->nullable()
                ->after('deleted_at');

            $table->foreign('grade_id', 'merit_lists_grade_fk')
                ->references('id')
                ->on('grades');

            $table->foreign('stream_id', 'merit_lists_stream_fk')
                ->references('id')
                ->on('streams');

            $table->foreign(
                'overall_grading_system_id',
                'merit_lists_grading_system_fk'
            )
                ->references('id')
                ->on('grading_systems');

            $table->foreign(
                'overall_grading_scale_id',
                'merit_lists_grading_scale_fk'
            )
                ->references('id')
                ->on('grading_scales');

            $table->foreign('generated_by', 'merit_lists_generated_by_fk')
                ->references('id')
                ->on('users');

            $table->foreign('deleted_by', 'merit_lists_deleted_by_fk')
                ->references('id')
                ->on('users');

            $table->unique(
                ['school_id', 'exam_id', 'learner_id'],
                'merit_lists_school_exam_learner_unique'
            );

            $table->index(
                ['school_id', 'exam_id', 'grade_id'],
                'merit_lists_exam_grade_idx'
            );

            $table->index(
                ['school_id', 'exam_id', 'stream_id'],
                'merit_lists_exam_stream_idx'
            );

            $table->index(
                ['school_id', 'exam_id', 'grade_position'],
                'merit_lists_grade_position_idx'
            );

            $table->index(
                ['school_id', 'exam_id', 'status'],
                'merit_lists_status_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::table('merit_lists', function (Blueprint $table) {
            $table->dropForeign('merit_lists_grade_fk');
            $table->dropForeign('merit_lists_stream_fk');
            $table->dropForeign('merit_lists_grading_system_fk');
            $table->dropForeign('merit_lists_grading_scale_fk');
            $table->dropForeign('merit_lists_generated_by_fk');
            $table->dropForeign('merit_lists_deleted_by_fk');

            $table->dropUnique('merit_lists_school_exam_learner_unique');

            $table->dropIndex('merit_lists_exam_grade_idx');
            $table->dropIndex('merit_lists_exam_stream_idx');
            $table->dropIndex('merit_lists_grade_position_idx');
            $table->dropIndex('merit_lists_status_idx');

            $table->dropColumn([
                'maximum_marks',
                'average_percentage',
                'overall_grading_system_id',
                'overall_grading_scale_id',
                'ranking_method',
                'status',
                'generated_by',
                'generated_at',
                'published_at',
                'updated_at',
                'is_deleted',
                'deleted_at',
                'deleted_by',
            ]);
        });
    }
};
