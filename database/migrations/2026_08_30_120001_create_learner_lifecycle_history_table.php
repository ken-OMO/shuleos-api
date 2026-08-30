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
        Schema::create(
            'learner_lifecycle_history',
            function (Blueprint $table): void {
                $table->uuid('id')->primary();

                $table->uuid('school_id');
                $table->uuid('learner_id');

                $table->string('from_status', 20)->nullable();
                $table->string('to_status', 20);

                $table->date('effective_date');
                $table->string('reason', 500);

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
                    [
                        'school_id',
                        'learner_id',
                        'changed_at',
                    ],
                    'learner_lifecycle_history_lookup_idx'
                );

                $table->index(
                    [
                        'school_id',
                        'to_status',
                        'effective_date',
                    ],
                    'learner_lifecycle_history_status_idx'
                );
            }
        );

        DB::statement(
            <<<'SQL'
            ALTER TABLE learner_lifecycle_history
            ADD CONSTRAINT learner_lifecycle_from_status_check
            CHECK (
                from_status IS NULL
                OR from_status IN (
                    'active',
                    'withdrawn',
                    'transferred',
                    'graduated'
                )
            )
            SQL
        );

        DB::statement(
            <<<'SQL'
            ALTER TABLE learner_lifecycle_history
            ADD CONSTRAINT learner_lifecycle_to_status_check
            CHECK (
                to_status IN (
                    'active',
                    'withdrawn',
                    'transferred',
                    'graduated'
                )
            )
            SQL
        );

        DB::statement(
            <<<'SQL'
            ALTER TABLE learner_lifecycle_history
            ADD CONSTRAINT learner_lifecycle_transition_check
            CHECK (
                from_status IS NULL
                OR from_status <> to_status
            )
            SQL
        );
    }

    public function down(): void
    {
        Schema::dropIfExists(
            'learner_lifecycle_history'
        );
    }
};
