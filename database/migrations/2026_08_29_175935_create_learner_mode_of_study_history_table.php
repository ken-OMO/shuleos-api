<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('learner_mode_of_study_history', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->uuid('school_id');
            $table->uuid('learner_id');

            $table->string('from_mode', 20)->nullable();
            $table->string('to_mode', 20);

            $table->string('reason', 500)->nullable();

            $table->uuid('changed_by');

            $table->timestampTz('changed_at');
            $table->timestampTz('created_at');

            $table->foreign('school_id')
                ->references('id')
                ->on('schools')
                ->restrictOnDelete();

            $table->foreign('learner_id')
                ->references('id')
                ->on('learners')
                ->restrictOnDelete();

            $table->foreign('changed_by')
                ->references('id')
                ->on('users')
                ->restrictOnDelete();

            $table->index(
                ['school_id', 'learner_id', 'changed_at'],
                'learner_mode_history_lookup_idx'
            );

            $table->index(
                ['school_id', 'to_mode'],
                'learner_mode_history_destination_idx'
            );
        });

        DB::statement(
            <<<'SQL'
            ALTER TABLE learner_mode_of_study_history
            ADD CONSTRAINT learner_mode_history_from_mode_check
            CHECK (
                from_mode IS NULL
                OR from_mode IN ('day_scholar', 'boarder')
            )
            SQL
        );

        DB::statement(
            <<<'SQL'
            ALTER TABLE learner_mode_of_study_history
            ADD CONSTRAINT learner_mode_history_to_mode_check
            CHECK (
                to_mode IN ('day_scholar', 'boarder')
            )
            SQL
        );

        DB::statement(
            <<<'SQL'
            ALTER TABLE learner_mode_of_study_history
            ADD CONSTRAINT learner_mode_history_transition_check
            CHECK (
                from_mode IS NULL
                OR from_mode <> to_mode
            )
            SQL
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('learner_mode_of_study_history');
    }
};
