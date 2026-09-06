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
        Schema::create('hostel_staff_assignments', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->uuid('school_id');
            $table->uuid('hostel_id');
            $table->uuid('user_id');

            $table->string('responsibility_role', 100);

            $table->date('effective_from');
            $table->date('effective_to')->nullable();

            $table->boolean('active')->default(true);

            $table->uuid('assigned_by');
            $table->uuid('ended_by')->nullable();
            $table->timestampTz('ended_at')->nullable();
            $table->string('end_reason', 500)->nullable();

            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();
        });

        /*
         * Explicit tenant ownership.
         */
        DB::statement(
            <<<'SQL'
            ALTER TABLE hostel_staff_assignments
            ADD CONSTRAINT hostel_staff_assignments_school_foreign
            FOREIGN KEY (school_id)
            REFERENCES schools (id)
            ON DELETE RESTRICT
            SQL
        );

        /*
         * Tenant-safe Boarding facility relationship.
         */
        DB::statement(
            <<<'SQL'
            ALTER TABLE hostel_staff_assignments
            ADD CONSTRAINT hostel_staff_assignments_school_hostel_foreign
            FOREIGN KEY (school_id, hostel_id)
            REFERENCES hostels (school_id, id)
            ON DELETE RESTRICT
            SQL
        );

        /*
         * Tenant-safe responsible User relationship.
         */
        DB::statement(
            <<<'SQL'
            ALTER TABLE hostel_staff_assignments
            ADD CONSTRAINT hostel_staff_assignments_school_user_foreign
            FOREIGN KEY (school_id, user_id)
            REFERENCES users (school_id, id)
            ON DELETE RESTRICT
            SQL
        );

        /*
         * Tenant-safe assigning actor relationship.
         */
        DB::statement(
            <<<'SQL'
            ALTER TABLE hostel_staff_assignments
            ADD CONSTRAINT hostel_staff_assignments_school_assigned_by_foreign
            FOREIGN KEY (school_id, assigned_by)
            REFERENCES users (school_id, id)
            ON DELETE RESTRICT
            SQL
        );

        /*
         * Tenant-safe ending actor relationship.
         */
        DB::statement(
            <<<'SQL'
            ALTER TABLE hostel_staff_assignments
            ADD CONSTRAINT hostel_staff_assignments_school_ended_by_foreign
            FOREIGN KEY (school_id, ended_by)
            REFERENCES users (school_id, id)
            ON DELETE RESTRICT
            SQL
        );

        /*
         * Effective-date consistency.
         */
        DB::statement(
            <<<'SQL'
            ALTER TABLE hostel_staff_assignments
            ADD CONSTRAINT hostel_staff_assignments_effective_dates_check
            CHECK (
                effective_to IS NULL
                OR effective_to >= effective_from
            )
            SQL
        );

        /*
         * One row represents one responsibility episode.
         *
         * Current:
         * - active
         * - no effective end
         * - no ending actor
         * - no ending timestamp
         * - no end reason
         *
         * Ended:
         * - inactive
         * - effective end present
         * - ending actor present
         * - ending timestamp present
         *
         * End reason remains optional.
         */
        DB::statement(
            <<<'SQL'
            ALTER TABLE hostel_staff_assignments
            ADD CONSTRAINT hostel_staff_assignments_lifecycle_check
            CHECK (
                (
                    active IS TRUE
                    AND effective_to IS NULL
                    AND ended_by IS NULL
                    AND ended_at IS NULL
                    AND end_reason IS NULL
                )
                OR
                (
                    active IS FALSE
                    AND effective_to IS NOT NULL
                    AND ended_by IS NOT NULL
                    AND ended_at IS NOT NULL
                )
            )
            SQL
        );

        /*
         * Prevent the same User from holding the same current
         * responsibility role for the same Hostel more than once.
         *
         * Multiple different Users and multiple different roles remain valid.
         */
        DB::statement(
            <<<'SQL'
            CREATE UNIQUE INDEX hostel_staff_assignments_active_identity_unique
            ON hostel_staff_assignments (
                school_id,
                hostel_id,
                user_id,
                responsibility_role
            )
            WHERE active IS TRUE
            SQL
        );

        /*
         * Operational lookup indexes.
         */
        DB::statement(
            <<<'SQL'
            CREATE INDEX hostel_staff_assignments_school_hostel_active_idx
            ON hostel_staff_assignments (
                school_id,
                hostel_id,
                active
            )
            SQL
        );

        DB::statement(
            <<<'SQL'
            CREATE INDEX hostel_staff_assignments_school_user_idx
            ON hostel_staff_assignments (
                school_id,
                user_id
            )
            SQL
        );

        DB::statement(
            <<<'SQL'
            CREATE INDEX hostel_staff_assignments_school_role_idx
            ON hostel_staff_assignments (
                school_id,
                responsibility_role
            )
            SQL
        );

        DB::statement(
            <<<'SQL'
            CREATE INDEX hostel_staff_assignments_school_effective_idx
            ON hostel_staff_assignments (
                school_id,
                effective_from,
                effective_to
            )
            SQL
        );
    }

    public function down(): void
    {
        /*
         * All table-owned indexes, CHECK constraints and foreign keys
         * are removed atomically with the table.
         */
        Schema::dropIfExists('hostel_staff_assignments');
    }
};
