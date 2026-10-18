<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('exam_results', function (Blueprint $table) {
            $table->boolean('is_absent')->default(false);
        });

        Schema::table('mark_entry_batch_items', function (Blueprint $table) {
            $table->boolean('is_absent')->default(false);
            $table->boolean('previous_is_absent')->nullable();
            $table->decimal('marks', 8, 2)->nullable()->change();
        });

        Schema::table('mark_correction_requests', function (Blueprint $table) {
            $table->boolean('previous_is_absent')->nullable();
            $table->boolean('proposed_is_absent')->nullable();
        });

        Schema::table('learning_area_results', function (Blueprint $table) {
            $table->boolean('is_absent')->default(false);
            $table->decimal('marks_obtained', 8, 2)->nullable()->change();
            $table->decimal('percentage', 6, 2)->nullable()->change();
            $table->uuid('grading_system_id')->nullable()->change();
            $table->uuid('grading_scale_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Never erase absence records or fabricate scores during rollback.
        $hasAbsence = DB::table('exam_results')->where('is_absent', true)->exists()
            || DB::table('mark_entry_batch_items')->where('is_absent', true)->exists()
            || DB::table('learning_area_results')->where('is_absent', true)->exists();

        $hasHistory = DB::table('mark_entry_batch_items')
            ->whereNotNull('previous_is_absent')->exists()
            || DB::table('mark_correction_requests')
                ->whereNotNull('previous_is_absent')->exists()
            || DB::table('mark_correction_requests')
                ->whereNotNull('proposed_is_absent')->exists();

        $hasNullValues = DB::table('mark_entry_batch_items')->whereNull('marks')->exists()
            || DB::table('learning_area_results')
                ->where(function ($query) {
                    $query->whereNull('marks_obtained')
                        ->orWhereNull('percentage')
                        ->orWhereNull('grading_system_id')
                        ->orWhereNull('grading_scale_id');
                })->exists();

        if ($hasAbsence || $hasHistory || $hasNullValues) {
            throw new RuntimeException(
                'Cannot roll back exam absence fields while absence data, correction history or nullable processed values remain.'
            );
        }

        Schema::table('learning_area_results', function (Blueprint $table) {
            $table->decimal('marks_obtained', 8, 2)->nullable(false)->change();
            $table->decimal('percentage', 6, 2)->nullable(false)->change();
            $table->uuid('grading_system_id')->nullable(false)->change();
            $table->uuid('grading_scale_id')->nullable(false)->change();
            $table->dropColumn('is_absent');
        });

        Schema::table('mark_correction_requests', function (Blueprint $table) {
            $table->dropColumn(['previous_is_absent', 'proposed_is_absent']);
        });

        Schema::table('mark_entry_batch_items', function (Blueprint $table) {
            $table->decimal('marks', 8, 2)->nullable(false)->change();
            $table->dropColumn(['is_absent', 'previous_is_absent']);
        });

        Schema::table('exam_results', function (Blueprint $table) {
            $table->dropColumn('is_absent');
        });
    }
};
