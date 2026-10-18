<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exam_grading_snapshots', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools');
            $table->foreignUuid('exam_id')->constrained('exams');
            $table->foreignUuid('education_level_id')->constrained('education_levels');
            $table->foreignUuid('captured_by')->nullable()->constrained('users');
            $table->timestamp('captured_at');
            $table->json('rules');

            $table->unique(
                ['school_id', 'exam_id', 'education_level_id'],
                'exam_grading_snapshots_level_unique'
            );
        });

        foreach ([
            'learning_area_results',
            'merit_lists',
            'report_cards',
            'report_card_learning_areas',
        ] as $name) {
            Schema::table($name, function (Blueprint $table) {
                // Null identifies records without a captured grading snapshot.
                $table->json('grading_snapshot')->nullable();
            });
        }
    }

    public function down(): void
    {
        // Check every table before removing any snapshot columns or rules.
        if (DB::table('exam_grading_snapshots')->exists()) {
            throw new RuntimeException(
                'Cannot remove captured exam grading rules during rollback.'
            );
        }

        $tables = [
            'learning_area_results',
            'merit_lists',
            'report_cards',
            'report_card_learning_areas',
        ];

        foreach ($tables as $name) {
            if (DB::table($name)->whereNotNull('grading_snapshot')->exists()) {
                throw new RuntimeException(
                    'Cannot remove awarded grading snapshots during rollback.'
                );
            }
        }

        foreach (array_reverse($tables) as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dropColumn('grading_snapshot');
            });
        }

        Schema::dropIfExists('exam_grading_snapshots');
    }
};
