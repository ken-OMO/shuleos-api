<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('report_card_learning_areas', function (Blueprint $table) {
            $table->uuid('learning_area_result_id')
                ->nullable()
                ->after('learning_area_id');

            $table->decimal('marks_obtained', 8, 2)
                ->nullable()
                ->after('score');

            $table->decimal('maximum_marks', 8, 2)
                ->nullable()
                ->after('marks_obtained');

            $table->decimal('percentage', 6, 2)
                ->nullable()
                ->after('maximum_marks');

            $table->uuid('grading_system_id')
                ->nullable()
                ->after('percentage');

            $table->uuid('grading_scale_id')
                ->nullable()
                ->after('grading_system_id');

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

            $table->foreign(
                'learning_area_result_id',
                'report_card_areas_result_fk'
            )
                ->references('id')
                ->on('learning_area_results');

            $table->foreign(
                'grading_system_id',
                'report_card_areas_grading_system_fk'
            )
                ->references('id')
                ->on('grading_systems');

            $table->foreign(
                'grading_scale_id',
                'report_card_areas_grading_scale_fk'
            )
                ->references('id')
                ->on('grading_scales');

            $table->foreign(
                'deleted_by',
                'report_card_areas_deleted_by_fk'
            )
                ->references('id')
                ->on('users');

            $table->unique(
                ['report_card_id', 'learning_area_id'],
                'report_card_areas_report_learning_unique'
            );

            $table->index(
                ['report_card_id', 'is_deleted'],
                'report_card_areas_current_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::table('report_card_learning_areas', function (Blueprint $table) {
            $table->dropForeign('report_card_areas_result_fk');
            $table->dropForeign('report_card_areas_grading_system_fk');
            $table->dropForeign('report_card_areas_grading_scale_fk');
            $table->dropForeign('report_card_areas_deleted_by_fk');

            $table->dropUnique('report_card_areas_report_learning_unique');
            $table->dropIndex('report_card_areas_current_idx');

            $table->dropColumn([
                'learning_area_result_id',
                'marks_obtained',
                'maximum_marks',
                'percentage',
                'grading_system_id',
                'grading_scale_id',
                'updated_at',
                'is_deleted',
                'deleted_at',
                'deleted_by',
            ]);
        });
    }
};
