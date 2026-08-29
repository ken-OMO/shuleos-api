<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('learner_placement_history', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->uuid('school_id');
            $table->uuid('learner_id');

            $table->uuid('from_grade_id');
            $table->uuid('from_stream_id');

            $table->uuid('to_grade_id');
            $table->uuid('to_stream_id');

            $table->string('placement_type', 30);
            $table->string('reason', 500)->nullable();

            $table->uuid('placed_by');
            $table->timestampTz('placed_at');
            $table->timestampTz('created_at');

            $table->foreign('school_id')
                ->references('id')
                ->on('schools')
                ->restrictOnDelete();

            $table->foreign('learner_id')
                ->references('id')
                ->on('learners')
                ->restrictOnDelete();

            $table->foreign('from_grade_id')
                ->references('id')
                ->on('grades')
                ->restrictOnDelete();

            $table->foreign('from_stream_id')
                ->references('id')
                ->on('streams')
                ->restrictOnDelete();

            $table->foreign('to_grade_id')
                ->references('id')
                ->on('grades')
                ->restrictOnDelete();

            $table->foreign('to_stream_id')
                ->references('id')
                ->on('streams')
                ->restrictOnDelete();

            $table->foreign('placed_by')
                ->references('id')
                ->on('users')
                ->restrictOnDelete();

            $table->index(
                ['school_id', 'learner_id', 'placed_at'],
                'learner_placement_history_lookup_idx'
            );

            $table->index(
                ['school_id', 'to_grade_id', 'to_stream_id'],
                'learner_placement_history_destination_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('learner_placement_history');
    }
};
