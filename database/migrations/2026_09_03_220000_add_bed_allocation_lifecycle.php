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
        /*
         * Existing allocation rows must have an unambiguous legacy state.
         *
         * active = true  => release_date must be NULL
         * active = false => release_date must be present
         *
         * We refuse to invent historical meaning for inconsistent rows.
         */
        $ambiguousRows = DB::table('bed_allocations')
            ->where(function ($query): void {
                $query
                    ->where(function ($nested): void {
                        $nested
                            ->where('active', true)
                            ->whereNotNull('release_date');
                    })
                    ->orWhere(function ($nested): void {
                        $nested
                            ->where('active', false)
                            ->whereNull('release_date');
                    })
                    ->orWhereNull('active');
            })
            ->count();

        if ($ambiguousRows > 0) {
            throw new RuntimeException(
                'Bed allocation lifecycle migration found ambiguous legacy allocation state.'
            );
        }

        Schema::table(
            'bed_allocations',
            function (Blueprint $table): void {
                $table->string('status', 20)
                    ->nullable();

                $table->index(
                    [
                        'school_id',
                        'learner_id',
                        'status',
                        'allocation_date',
                    ],
                    'bed_allocations_lifecycle_lookup_idx'
                );
            }
        );

        /*
         * Existing active rows are current occupancy episodes.
         *
         * Existing inactive rows with release_date are historical releases.
         * There is no trustworthy legacy signal that could classify an old
         * inactive row as a transfer, so those rows become released.
         */
        DB::table('bed_allocations')
            ->where('active', true)
            ->update([
                'status' => 'active',
            ]);

        DB::table('bed_allocations')
            ->where('active', false)
            ->update([
                'status' => 'released',
            ]);

        DB::statement(
            'ALTER TABLE bed_allocations ALTER COLUMN status SET NOT NULL'
        );

        DB::statement(
            <<<'SQL'
            ALTER TABLE bed_allocations
            ADD CONSTRAINT bed_allocations_status_check
            CHECK (
                status IN (
                    'active',
                    'released',
                    'transferred'
                )
            )
            SQL
        );

        DB::statement(
            <<<'SQL'
            ALTER TABLE bed_allocations
            ADD CONSTRAINT bed_allocations_status_active_consistency_check
            CHECK (
                (
                    status = 'active'
                    AND active IS TRUE
                )
                OR (
                    status IN (
                        'released',
                        'transferred'
                    )
                    AND active IS FALSE
                )
            )
            SQL
        );

        DB::statement(
            <<<'SQL'
            ALTER TABLE bed_allocations
            ADD CONSTRAINT bed_allocations_release_date_consistency_check
            CHECK (
                (
                    status = 'active'
                    AND release_date IS NULL
                )
                OR (
                    status IN (
                        'released',
                        'transferred'
                    )
                    AND release_date IS NOT NULL
                )
            )
            SQL
        );

        Schema::create(
            'bed_allocation_history',
            function (Blueprint $table): void {
                $table->uuid('id')->primary();

                $table->uuid('school_id');
                $table->uuid('learner_id');

                /*
                 * event_id is the logical lifecycle event correlation key.
                 *
                 * Release:
                 *   one history row under one event_id.
                 *
                 * Transfer:
                 *   one history row correlating source and destination
                 *   allocation episodes under one event_id.
                 */
                $table->uuid('event_id')->unique();

                $table->string('event_type', 20);

                $table->uuid('source_allocation_id');
                $table->uuid('destination_allocation_id')
                    ->nullable();

                $table->string('from_status', 20);
                $table->string('to_status', 20);

                $table->date('effective_date');

                $table->string('reason', 500)
                    ->nullable();

                $table->uuid('changed_by');

                $table->timestampTz('changed_at');
                $table->timestampTz('created_at');

                $table->foreign('school_id')
                    ->references('id')
                    ->on('schools')
                    ->restrictOnDelete();

                $table->index(
                    [
                        'school_id',
                        'learner_id',
                        'changed_at',
                    ],
                    'bed_allocation_history_learner_idx'
                );

                $table->index(
                    [
                        'school_id',
                        'source_allocation_id',
                        'changed_at',
                    ],
                    'bed_allocation_history_source_idx'
                );

                $table->index(
                    [
                        'school_id',
                        'destination_allocation_id',
                        'changed_at',
                    ],
                    'bed_allocation_history_destination_idx'
                );
            }
        );

        /*
         * Composite uniqueness exists only to support tenant-safe foreign keys.
         */
        DB::statement(
            <<<'SQL'
            CREATE UNIQUE INDEX bed_allocations_school_id_id_unique
            ON bed_allocations (school_id, id)
            SQL
        );

        /*
         * users.school_id is intentionally nullable because platform-level
         * identities exist. This composite unique index does not change that
         * model; it provides the database key required to prove that an actor
         * recorded on tenant-owned Boarding history belongs to that school.
         */
        DB::statement(
            <<<'SQL'
            CREATE UNIQUE INDEX users_school_id_id_unique
            ON users (school_id, id)
            SQL
        );

        DB::statement(
            <<<'SQL'
            ALTER TABLE bed_allocation_history
            ADD CONSTRAINT bed_allocation_history_school_learner_foreign
            FOREIGN KEY (school_id, learner_id)
            REFERENCES learners (school_id, id)
            ON DELETE RESTRICT
            SQL
        );

        DB::statement(
            <<<'SQL'
            ALTER TABLE bed_allocation_history
            ADD CONSTRAINT bed_allocation_history_school_actor_foreign
            FOREIGN KEY (school_id, changed_by)
            REFERENCES users (school_id, id)
            ON DELETE RESTRICT
            SQL
        );

        DB::statement(
            <<<'SQL'
            ALTER TABLE bed_allocation_history
            ADD CONSTRAINT bed_allocation_history_school_source_foreign
            FOREIGN KEY (school_id, source_allocation_id)
            REFERENCES bed_allocations (school_id, id)
            ON DELETE RESTRICT
            SQL
        );

        DB::statement(
            <<<'SQL'
            ALTER TABLE bed_allocation_history
            ADD CONSTRAINT bed_allocation_history_school_destination_foreign
            FOREIGN KEY (school_id, destination_allocation_id)
            REFERENCES bed_allocations (school_id, id)
            ON DELETE RESTRICT
            SQL
        );

        DB::statement(
            <<<'SQL'
            ALTER TABLE bed_allocation_history
            ADD CONSTRAINT bed_allocation_history_event_type_check
            CHECK (
                event_type IN (
                    'release',
                    'transfer'
                )
            )
            SQL
        );

        DB::statement(
            <<<'SQL'
            ALTER TABLE bed_allocation_history
            ADD CONSTRAINT bed_allocation_history_from_status_check
            CHECK (
                from_status = 'active'
            )
            SQL
        );

        DB::statement(
            <<<'SQL'
            ALTER TABLE bed_allocation_history
            ADD CONSTRAINT bed_allocation_history_to_status_check
            CHECK (
                to_status IN (
                    'released',
                    'transferred'
                )
            )
            SQL
        );

        DB::statement(
            <<<'SQL'
            ALTER TABLE bed_allocation_history
            ADD CONSTRAINT bed_allocation_history_transition_check
            CHECK (
                from_status <> to_status
            )
            SQL
        );

        DB::statement(
            <<<'SQL'
            ALTER TABLE bed_allocation_history
            ADD CONSTRAINT bed_allocation_history_event_consistency_check
            CHECK (
                (
                    event_type = 'release'
                    AND to_status = 'released'
                    AND destination_allocation_id IS NULL
                )
                OR (
                    event_type = 'transfer'
                    AND to_status = 'transferred'
                    AND destination_allocation_id IS NOT NULL
                )
            )
            SQL
        );
        /*
         * Boarding allocation lifecycle history is an append-only ledger.
         *
         * Application/model conventions are not an adequate integrity
         * boundary because Query Builder, raw SQL, maintenance code, or
         * future application paths could otherwise mutate historical facts.
         *
         * PostgreSQL therefore rejects UPDATE and DELETE for every row.
         * INSERT remains permitted for legitimate lifecycle events.
         */
        DB::statement(
            <<<'SQL'
            CREATE OR REPLACE FUNCTION bed_allocation_history_prevent_mutation()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            BEGIN
                RAISE EXCEPTION
                    'bed_allocation_history is append-only; UPDATE and DELETE are forbidden'
                    USING ERRCODE = '55000';
            END;
            $$
            SQL
        );

        DB::statement(
            <<<'SQL'
            CREATE TRIGGER bed_allocation_history_immutable_trigger
            BEFORE UPDATE OR DELETE
            ON bed_allocation_history
            FOR EACH ROW
            EXECUTE FUNCTION bed_allocation_history_prevent_mutation()
            SQL
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('bed_allocation_history');

        /*
         * Dropping the history table removes its trigger, but PostgreSQL
         * trigger functions are standalone schema objects and survive the
         * table drop. Remove the function explicitly so rollback/reapply and
         * migrate:fresh remain reversible.
         */
        DB::statement(
            'DROP FUNCTION IF EXISTS bed_allocation_history_prevent_mutation()'
        );

        DB::statement(
            'DROP INDEX IF EXISTS bed_allocations_school_id_id_unique'
        );

        DB::statement(
            'DROP INDEX IF EXISTS users_school_id_id_unique'
        );

        DB::statement(
            'ALTER TABLE bed_allocations DROP CONSTRAINT IF EXISTS bed_allocations_release_date_consistency_check'
        );

        DB::statement(
            'ALTER TABLE bed_allocations DROP CONSTRAINT IF EXISTS bed_allocations_status_active_consistency_check'
        );

        DB::statement(
            'ALTER TABLE bed_allocations DROP CONSTRAINT IF EXISTS bed_allocations_status_check'
        );

        Schema::table(
            'bed_allocations',
            function (Blueprint $table): void {
                $table->dropIndex(
                    'bed_allocations_lifecycle_lookup_idx'
                );

                $table->dropColumn('status');
            }
        );
    }
};
