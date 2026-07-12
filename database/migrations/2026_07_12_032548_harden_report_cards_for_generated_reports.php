<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('report_cards', function (Blueprint $table) {
            $table->uuid('merit_list_id')
                ->nullable()
                ->after('term_id');

            $table->uuid('grade_id')
                ->nullable()
                ->after('merit_list_id');

            $table->uuid('stream_id')
                ->nullable()
                ->after('grade_id');

            $table->decimal('maximum_marks', 10, 2)
                ->nullable()
                ->after('overall_score');

            $table->decimal('average_percentage', 6, 2)
                ->nullable()
                ->after('maximum_marks');

            $table->uuid('overall_grading_system_id')
                ->nullable()
                ->after('overall_grade');

            $table->uuid('overall_grading_scale_id')
                ->nullable()
                ->after('overall_grading_system_id');

            $table->integer('attendance_present')
                ->nullable()
                ->after('total_learners');

            $table->integer('attendance_absent')
                ->nullable()
                ->after('attendance_present');

            $table->integer('attendance_late')
                ->nullable()
                ->after('attendance_absent');

            $table->integer('attendance_total_sessions')
                ->nullable()
                ->after('attendance_late');

            $table->uuid('pathway_recommendation_id')
                ->nullable()
                ->after('pathway_recommendation');

            $table->string('status', 30)
                ->default('generated')
                ->after('pathway_recommendation_id');

            $table->uuid('generated_by')
                ->nullable()
                ->after('status');

            $table->uuid('published_by')
                ->nullable()
                ->after('generated_at');

            $table->timestamp('published_at')
                ->nullable()
                ->after('published_by');

            $table->timestamp('created_at')
                ->nullable()
                ->after('published_at');

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

            $table->foreign('merit_list_id', 'report_cards_merit_list_fk')
                ->references('id')
                ->on('merit_lists');

            $table->foreign('grade_id', 'report_cards_grade_fk')
                ->references('id')
                ->on('grades');

            $table->foreign('stream_id', 'report_cards_stream_fk')
                ->references('id')
                ->on('streams');

            $table->foreign(
                'overall_grading_system_id',
                'report_cards_grading_system_fk'
            )
                ->references('id')
                ->on('grading_systems');

            $table->foreign(
                'overall_grading_scale_id',
                'report_cards_grading_scale_fk'
            )
                ->references('id')
                ->on('grading_scales');

            $table->foreign(
                'pathway_recommendation_id',
                'report_cards_pathway_fk'
            )
                ->references('id')
                ->on('pathway_recommendations');

            $table->foreign('generated_by', 'report_cards_generated_by_fk')
                ->references('id')
                ->on('users');

            $table->foreign('published_by', 'report_cards_published_by_fk')
                ->references('id')
                ->on('users');

            $table->foreign('deleted_by', 'report_cards_deleted_by_fk')
                ->references('id')
                ->on('users');

            $table->unique(
                ['school_id', 'exam_id', 'learner_id'],
                'report_cards_school_exam_learner_unique'
            );

            $table->index(
                ['school_id', 'exam_id', 'grade_id'],
                'report_cards_exam_grade_idx'
            );

            $table->index(
                ['school_id', 'exam_id', 'stream_id'],
                'report_cards_exam_stream_idx'
            );

            $table->index(
                ['school_id', 'exam_id', 'status'],
                'report_cards_status_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::table('report_cards', function (Blueprint $table) {
            $table->dropForeign('report_cards_merit_list_fk');
            $table->dropForeign('report_cards_grade_fk');
            $table->dropForeign('report_cards_stream_fk');
            $table->dropForeign('report_cards_grading_system_fk');
            $table->dropForeign('report_cards_grading_scale_fk');
            $table->dropForeign('report_cards_pathway_fk');
            $table->dropForeign('report_cards_generated_by_fk');
            $table->dropForeign('report_cards_published_by_fk');
            $table->dropForeign('report_cards_deleted_by_fk');

            $table->dropUnique('report_cards_school_exam_learner_unique');

            $table->dropIndex('report_cards_exam_grade_idx');
            $table->dropIndex('report_cards_exam_stream_idx');
            $table->dropIndex('report_cards_status_idx');

            $table->dropColumn([
                'merit_list_id',
                'grade_id',
                'stream_id',
                'maximum_marks',
                'average_percentage',
                'overall_grading_system_id',
                'overall_grading_scale_id',
                'attendance_present',
                'attendance_absent',
                'attendance_late',
                'attendance_total_sessions',
                'pathway_recommendation_id',
                'status',
                'generated_by',
                'published_by',
                'published_at',
                'created_at',
                'updated_at',
                'is_deleted',
                'deleted_at',
                'deleted_by',
            ]);
        });
    }
};
