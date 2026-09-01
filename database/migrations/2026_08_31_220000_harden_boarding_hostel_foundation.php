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
         * Boarding is an existing legacy schema loaded from pgsql-schema.sql.
         * Establish explicit tenant ownership throughout the physical hierarchy
         * before exposing the domain through new application services.
         */

        Schema::table('hostels', function (Blueprint $table): void {
            $table->timestamp('updated_at')->nullable();
            $table->boolean('is_deleted')->default(false);
            $table->timestamp('deleted_at')->nullable();
            $table->uuid('deleted_by')->nullable();
        });

        Schema::table('hostel_rooms', function (Blueprint $table): void {
            $table->uuid('school_id')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->boolean('is_deleted')->default(false);
            $table->timestamp('deleted_at')->nullable();
            $table->uuid('deleted_by')->nullable();
        });

        Schema::table('hostel_beds', function (Blueprint $table): void {
            $table->uuid('school_id')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->boolean('is_deleted')->default(false);
            $table->timestamp('deleted_at')->nullable();
            $table->uuid('deleted_by')->nullable();
        });

        Schema::table('bed_allocations', function (Blueprint $table): void {
            $table->uuid('school_id')->nullable();
            $table->timestamp('updated_at')->nullable();
        });

        /*
         * Backfill tenant ownership only from authoritative server-owned
         * relationships.
         */
        DB::statement(
            <<<'SQL'
            UPDATE hostel_rooms AS room
            SET school_id = hostel.school_id
            FROM hostels AS hostel
            WHERE room.hostel_id = hostel.id
              AND room.school_id IS NULL
            SQL
        );

        DB::statement(
            <<<'SQL'
            UPDATE hostel_beds AS bed
            SET school_id = room.school_id
            FROM hostel_rooms AS room
            WHERE bed.room_id = room.id
              AND bed.school_id IS NULL
            SQL
        );

        DB::statement(
            <<<'SQL'
            UPDATE bed_allocations AS allocation
            SET school_id = bed.school_id
            FROM hostel_beds AS bed
            WHERE allocation.bed_id = bed.id
              AND allocation.school_id IS NULL
            SQL
        );

        /*
         * Fail closed if any legacy hierarchy cannot be assigned a tenant.
         */
        $unresolvedRooms = DB::table('hostel_rooms')
            ->whereNull('school_id')
            ->count();

        $unresolvedBeds = DB::table('hostel_beds')
            ->whereNull('school_id')
            ->count();

        $unresolvedAllocations = DB::table('bed_allocations')
            ->whereNull('school_id')
            ->count();

        if (
            $unresolvedRooms > 0
            || $unresolvedBeds > 0
            || $unresolvedAllocations > 0
        ) {
            throw new RuntimeException(
                'Boarding migration aborted: unresolved tenant ownership exists.'
            );
        }

        /*
         * Every allocation must agree with the learner's tenant.
         */
        $learnerTenantMismatch = DB::table('bed_allocations as allocation')
            ->join(
                'learners as learner',
                'learner.id',
                '=',
                'allocation.learner_id'
            )
            ->whereColumn(
                'allocation.school_id',
                '<>',
                'learner.school_id'
            )
            ->exists();

        if ($learnerTenantMismatch) {
            throw new RuntimeException(
                'Boarding migration aborted: learner tenant mismatch detected.'
            );
        }

        /*
         * Legacy MIXED hostels cannot silently survive the new contract.
         * We deliberately abort instead of guessing whether they are boys
         * or girls hostels.
         */
        $mixedHostels = DB::table('hostels')
            ->whereRaw('UPPER(hostel_type) = ?', ['MIXED'])
            ->exists();

        if ($mixedHostels) {
            throw new RuntimeException(
                'Boarding migration aborted: MIXED hostels must be classified as BOYS or GIRLS first.'
            );
        }

        /*
         * Normalize unambiguous legacy casing before adding the new check.
         */
        DB::table('hostels')
            ->whereRaw('UPPER(hostel_type) = ?', ['BOYS'])
            ->update(['hostel_type' => 'BOYS']);

        DB::table('hostels')
            ->whereRaw('UPPER(hostel_type) = ?', ['GIRLS'])
            ->update(['hostel_type' => 'GIRLS']);

        $invalidHostelType = DB::table('hostels')
            ->whereNotIn('hostel_type', ['BOYS', 'GIRLS'])
            ->exists();

        if ($invalidHostelType) {
            throw new RuntimeException(
                'Boarding migration aborted: invalid hostel type detected.'
            );
        }

        /*
         * Capacity must be meaningful where the legacy field is populated.
         */
        $invalidHostelCapacity = DB::table('hostels')
            ->whereNotNull('capacity')
            ->where('capacity', '<=', 0)
            ->exists();

        $invalidRoomCapacity = DB::table('hostel_rooms')
            ->whereNotNull('capacity')
            ->where('capacity', '<=', 0)
            ->exists();

        if ($invalidHostelCapacity || $invalidRoomCapacity) {
            throw new RuntimeException(
                'Boarding migration aborted: capacities must be greater than zero.'
            );
        }

        /*
         * Existing active allocation collisions must be resolved rather than
         * silently discarded.
         */
        $duplicateActiveBed = DB::table('bed_allocations')
            ->select('school_id', 'bed_id')
            ->where('active', true)
            ->groupBy('school_id', 'bed_id')
            ->havingRaw('COUNT(*) > 1')
            ->exists();

        $duplicateActiveLearner = DB::table('bed_allocations')
            ->select('school_id', 'learner_id')
            ->where('active', true)
            ->groupBy('school_id', 'learner_id')
            ->havingRaw('COUNT(*) > 1')
            ->exists();

        if ($duplicateActiveBed || $duplicateActiveLearner) {
            throw new RuntimeException(
                'Boarding migration aborted: conflicting active bed allocations exist.'
            );
        }

        /*
         * PostgreSQL requires the referenced column combination to be unique
         * before it can be the target of a composite foreign key.
         */
        DB::statement(
            'CREATE UNIQUE INDEX hostels_school_id_id_unique ON hostels (school_id, id)'
        );

        DB::statement(
            'CREATE UNIQUE INDEX hostel_rooms_school_id_id_unique ON hostel_rooms (school_id, id)'
        );

        DB::statement(
            'CREATE UNIQUE INDEX hostel_beds_school_id_id_unique ON hostel_beds (school_id, id)'
        );

        DB::statement(
            'CREATE UNIQUE INDEX learners_school_id_id_unique ON learners (school_id, id)'
        );

        /*
         * Tenant-scoped business uniqueness.
         */
        DB::statement(
            'CREATE UNIQUE INDEX hostels_school_name_unique
             ON hostels (school_id, hostel_name)
             WHERE is_deleted = false'
        );

        DB::statement(
            'CREATE UNIQUE INDEX hostel_rooms_hostel_name_unique
             ON hostel_rooms (school_id, hostel_id, room_name)
             WHERE is_deleted = false'
        );

        DB::statement(
            'CREATE UNIQUE INDEX hostel_beds_room_number_unique
             ON hostel_beds (school_id, room_id, bed_number)
             WHERE is_deleted = false'
        );

        /*
         * Database-level concurrency backstop:
         * one active learner per bed and one active bed per learner.
         */
        DB::statement(
            'CREATE UNIQUE INDEX bed_allocations_active_bed_unique
             ON bed_allocations (school_id, bed_id)
             WHERE active = true'
        );

        DB::statement(
            'CREATE UNIQUE INDEX bed_allocations_active_learner_unique
             ON bed_allocations (school_id, learner_id)
             WHERE active = true'
        );

        /*
         * Replace the legacy hostel-type contract.
         */
        DB::statement(
            'ALTER TABLE hostels
             DROP CONSTRAINT IF EXISTS chk_hostel_type'
        );

        DB::statement(
            <<<'SQL'
            ALTER TABLE hostels
            ADD CONSTRAINT hostels_type_check
            CHECK (hostel_type IN ('BOYS', 'GIRLS'))
            SQL
        );

        DB::statement(
            <<<'SQL'
            ALTER TABLE hostels
            ADD CONSTRAINT hostels_capacity_check
            CHECK (capacity IS NULL OR capacity > 0)
            SQL
        );

        DB::statement(
            <<<'SQL'
            ALTER TABLE hostel_rooms
            ADD CONSTRAINT hostel_rooms_capacity_check
            CHECK (capacity IS NULL OR capacity > 0)
            SQL
        );

        /*
         * Convert tenant ownership to mandatory after successful backfill.
         */
        DB::statement(
            'ALTER TABLE hostel_rooms ALTER COLUMN school_id SET NOT NULL'
        );

        DB::statement(
            'ALTER TABLE hostel_beds ALTER COLUMN school_id SET NOT NULL'
        );

        DB::statement(
            'ALTER TABLE bed_allocations ALTER COLUMN school_id SET NOT NULL'
        );

        /*
         * Every Boarding core table is explicitly tenant-owned.
         *
         * The composite hierarchy foreign keys below prevent cross-tenant
         * relationships between Boarding resources. These direct school
         * foreign keys additionally guarantee that each stored school_id
         * references a real tenant at the database layer.
         */
        DB::statement(
            <<<'SQL'
            ALTER TABLE hostel_rooms
            ADD CONSTRAINT fk_hr_school
            FOREIGN KEY (school_id)
            REFERENCES schools (id)
            ON DELETE RESTRICT
            SQL
        );

        DB::statement(
            <<<'SQL'
            ALTER TABLE hostel_beds
            ADD CONSTRAINT fk_hb_school
            FOREIGN KEY (school_id)
            REFERENCES schools (id)
            ON DELETE RESTRICT
            SQL
        );

        DB::statement(
            <<<'SQL'
            ALTER TABLE bed_allocations
            ADD CONSTRAINT fk_ba_school
            FOREIGN KEY (school_id)
            REFERENCES schools (id)
            ON DELETE RESTRICT
            SQL
        );

        /*
         * Replace legacy single-column cascade relationships with
         * tenant-safe composite relationships.
         */
        DB::statement(
            'ALTER TABLE hostel_rooms
             DROP CONSTRAINT IF EXISTS fk_hr_hostel'
        );

        DB::statement(
            'ALTER TABLE hostel_beds
             DROP CONSTRAINT IF EXISTS fk_hb_room'
        );

        DB::statement(
            'ALTER TABLE bed_allocations
             DROP CONSTRAINT IF EXISTS fk_ba_bed'
        );

        DB::statement(
            'ALTER TABLE bed_allocations
             DROP CONSTRAINT IF EXISTS fk_ba_learner'
        );

        DB::statement(
            <<<'SQL'
            ALTER TABLE hostel_rooms
            ADD CONSTRAINT hostel_rooms_school_hostel_foreign
            FOREIGN KEY (school_id, hostel_id)
            REFERENCES hostels (school_id, id)
            ON DELETE RESTRICT
            SQL
        );

        DB::statement(
            <<<'SQL'
            ALTER TABLE hostel_beds
            ADD CONSTRAINT hostel_beds_school_room_foreign
            FOREIGN KEY (school_id, room_id)
            REFERENCES hostel_rooms (school_id, id)
            ON DELETE RESTRICT
            SQL
        );

        DB::statement(
            <<<'SQL'
            ALTER TABLE bed_allocations
            ADD CONSTRAINT bed_allocations_school_bed_foreign
            FOREIGN KEY (school_id, bed_id)
            REFERENCES hostel_beds (school_id, id)
            ON DELETE RESTRICT
            SQL
        );

        DB::statement(
            <<<'SQL'
            ALTER TABLE bed_allocations
            ADD CONSTRAINT bed_allocations_school_learner_foreign
            FOREIGN KEY (school_id, learner_id)
            REFERENCES learners (school_id, id)
            ON DELETE RESTRICT
            SQL
        );

        /*
         * Operational lookup indexes.
         */
        DB::statement(
            'CREATE INDEX hostel_rooms_school_hostel_index
             ON hostel_rooms (school_id, hostel_id)'
        );

        DB::statement(
            'CREATE INDEX hostel_beds_school_room_index
             ON hostel_beds (school_id, room_id)'
        );

        DB::statement(
            'CREATE INDEX bed_allocations_school_learner_index
             ON bed_allocations (school_id, learner_id)'
        );

        DB::statement(
            'CREATE INDEX bed_allocations_school_bed_index
             ON bed_allocations (school_id, bed_id)'
        );
    }

    public function down(): void
    {
        /*
         * Remove new tenant-safe relationships first.
         */
        DB::statement(
            'ALTER TABLE bed_allocations
             DROP CONSTRAINT IF EXISTS bed_allocations_school_learner_foreign'
        );

        DB::statement(
            'ALTER TABLE bed_allocations
             DROP CONSTRAINT IF EXISTS bed_allocations_school_bed_foreign'
        );

        DB::statement(
            'ALTER TABLE hostel_beds
             DROP CONSTRAINT IF EXISTS hostel_beds_school_room_foreign'
        );

        DB::statement(
            'ALTER TABLE hostel_rooms
             DROP CONSTRAINT IF EXISTS hostel_rooms_school_hostel_foreign'
        );

        DB::statement(
            'DROP INDEX IF EXISTS bed_allocations_school_bed_index'
        );

        DB::statement(
            'DROP INDEX IF EXISTS bed_allocations_school_learner_index'
        );

        DB::statement(
            'DROP INDEX IF EXISTS hostel_beds_school_room_index'
        );

        DB::statement(
            'DROP INDEX IF EXISTS hostel_rooms_school_hostel_index'
        );

        DB::statement(
            'DROP INDEX IF EXISTS bed_allocations_active_learner_unique'
        );

        DB::statement(
            'DROP INDEX IF EXISTS bed_allocations_active_bed_unique'
        );

        DB::statement(
            'DROP INDEX IF EXISTS hostel_beds_room_number_unique'
        );

        DB::statement(
            'DROP INDEX IF EXISTS hostel_rooms_hostel_name_unique'
        );

        DB::statement(
            'DROP INDEX IF EXISTS hostels_school_name_unique'
        );

        DB::statement(
            'ALTER TABLE hostel_rooms
             ADD CONSTRAINT fk_hr_hostel
             FOREIGN KEY (hostel_id)
             REFERENCES hostels (id)
             ON DELETE CASCADE'
        );

        DB::statement(
            'ALTER TABLE hostel_beds
             ADD CONSTRAINT fk_hb_room
             FOREIGN KEY (room_id)
             REFERENCES hostel_rooms (id)
             ON DELETE CASCADE'
        );

        DB::statement(
            'ALTER TABLE bed_allocations
             ADD CONSTRAINT fk_ba_bed
             FOREIGN KEY (bed_id)
             REFERENCES hostel_beds (id)'
        );

        DB::statement(
            'ALTER TABLE bed_allocations
             ADD CONSTRAINT fk_ba_learner
             FOREIGN KEY (learner_id)
             REFERENCES learners (id)'
        );

        /*
         * Remove direct tenant foreign keys before removing the tenant
         * ownership columns during rollback.
         */
        DB::statement(
            'ALTER TABLE bed_allocations
             DROP CONSTRAINT IF EXISTS fk_ba_school'
        );

        DB::statement(
            'ALTER TABLE hostel_beds
             DROP CONSTRAINT IF EXISTS fk_hb_school'
        );

        DB::statement(
            'ALTER TABLE hostel_rooms
             DROP CONSTRAINT IF EXISTS fk_hr_school'
        );

        DB::statement(
            'ALTER TABLE hostel_rooms
             ALTER COLUMN school_id DROP NOT NULL'
        );

        DB::statement(
            'ALTER TABLE hostel_beds
             ALTER COLUMN school_id DROP NOT NULL'
        );

        DB::statement(
            'ALTER TABLE bed_allocations
             ALTER COLUMN school_id DROP NOT NULL'
        );

        DB::statement(
            'ALTER TABLE hostel_rooms
             DROP COLUMN school_id'
        );

        DB::statement(
            'ALTER TABLE hostel_beds
             DROP COLUMN school_id'
        );

        DB::statement(
            'ALTER TABLE bed_allocations
             DROP COLUMN school_id'
        );

        DB::statement(
            'ALTER TABLE hostels
             DROP CONSTRAINT IF EXISTS hostels_capacity_check'
        );

        DB::statement(
            'ALTER TABLE hostel_rooms
             DROP CONSTRAINT IF EXISTS hostel_rooms_capacity_check'
        );

        DB::statement(
            'ALTER TABLE hostels
             DROP CONSTRAINT IF EXISTS hostels_type_check'
        );

        DB::statement(
            <<<'SQL'
            ALTER TABLE hostels
            ADD CONSTRAINT chk_hostel_type
            CHECK (hostel_type IN ('BOYS', 'GIRLS', 'MIXED'))
            SQL
        );

        DB::statement(
            'DROP INDEX IF EXISTS learners_school_id_id_unique'
        );

        DB::statement(
            'DROP INDEX IF EXISTS hostel_beds_school_id_id_unique'
        );

        DB::statement(
            'DROP INDEX IF EXISTS hostel_rooms_school_id_id_unique'
        );

        DB::statement(
            'DROP INDEX IF EXISTS hostels_school_id_id_unique'
        );

        Schema::table('bed_allocations', function (Blueprint $table): void {
            $table->dropColumn('updated_at');
        });

        Schema::table('hostel_beds', function (Blueprint $table): void {
            $table->dropColumn([
                'updated_at',
                'is_deleted',
                'deleted_at',
                'deleted_by',
            ]);
        });

        Schema::table('hostel_rooms', function (Blueprint $table): void {
            $table->dropColumn([
                'updated_at',
                'is_deleted',
                'deleted_at',
                'deleted_by',
            ]);
        });

        Schema::table('hostels', function (Blueprint $table): void {
            $table->dropColumn([
                'updated_at',
                'is_deleted',
                'deleted_at',
                'deleted_by',
            ]);
        });
    }
};
