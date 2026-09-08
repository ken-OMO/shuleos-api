<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(
            'teacher_duty_occurrence_categories',
            function (Blueprint $table): void {
                $table->uuid('id')->primary();

                $table->uuid('school_id');

                $table->string('code', 100);
                $table->string('name', 150);
                $table->text('description')->nullable();

                $table->boolean('is_canonical')->default(false);
                $table->integer('display_order')->default(0);
                $table->boolean('active')->default(true);

                $table->uuid('created_by')->nullable();
                $table->uuid('deactivated_by')->nullable();
                $table->timestampTz('deactivated_at')->nullable();

                $table->timestampTz('created_at')->useCurrent();
                $table->timestampTz('updated_at')->useCurrent();
            }
        );

        DB::statement(
            <<<'SQL'
            ALTER TABLE teacher_duty_occurrence_categories
            ADD CONSTRAINT teacher_duty_occurrence_categories_school_foreign
            FOREIGN KEY (school_id)
            REFERENCES schools (id)
            ON DELETE RESTRICT
            SQL
        );

        DB::statement(
            <<<'SQL'
            ALTER TABLE teacher_duty_occurrence_categories
            ADD CONSTRAINT teacher_duty_occurrence_categories_school_created_by_foreign
            FOREIGN KEY (school_id, created_by)
            REFERENCES users (school_id, id)
            ON DELETE RESTRICT
            SQL
        );

        DB::statement(
            <<<'SQL'
            ALTER TABLE teacher_duty_occurrence_categories
            ADD CONSTRAINT teacher_duty_occurrence_categories_school_deactivated_by_foreign
            FOREIGN KEY (school_id, deactivated_by)
            REFERENCES users (school_id, id)
            ON DELETE RESTRICT
            SQL
        );

        DB::statement(
            <<<'SQL'
            ALTER TABLE teacher_duty_occurrence_categories
            ADD CONSTRAINT teacher_duty_occurrence_categories_code_format_check
            CHECK (
                code ~ '^[a-z0-9]+(_[a-z0-9]+)*$'
            )
            SQL
        );

        DB::statement(
            <<<'SQL'
            ALTER TABLE teacher_duty_occurrence_categories
            ADD CONSTRAINT teacher_duty_occurrence_categories_display_order_check
            CHECK (display_order >= 0)
            SQL
        );

        DB::statement(
            <<<'SQL'
            ALTER TABLE teacher_duty_occurrence_categories
            ADD CONSTRAINT teacher_duty_occurrence_categories_canonical_identity_check
            CHECK (
                (
                    is_canonical IS TRUE
                    AND code IN (
                        'discipline',
                        'attendance',
                        'health_safety',
                        'cleanliness',
                        'property_facilities',
                        'academic',
                        'visitor_security',
                        'general'
                    )
                )
                OR
                (
                    is_canonical IS FALSE
                    AND code NOT IN (
                        'discipline',
                        'attendance',
                        'health_safety',
                        'cleanliness',
                        'property_facilities',
                        'academic',
                        'visitor_security',
                        'general'
                    )
                )
            )
            SQL
        );

        DB::statement(
            <<<'SQL'
            ALTER TABLE teacher_duty_occurrence_categories
            ADD CONSTRAINT teacher_duty_occurrence_categories_provenance_check
            CHECK (
                (
                    is_canonical IS TRUE
                    AND created_by IS NULL
                )
                OR
                (
                    is_canonical IS FALSE
                    AND created_by IS NOT NULL
                )
            )
            SQL
        );

        DB::statement(
            <<<'SQL'
            ALTER TABLE teacher_duty_occurrence_categories
            ADD CONSTRAINT teacher_duty_occurrence_categories_lifecycle_check
            CHECK (
                (
                    active IS TRUE
                    AND deactivated_by IS NULL
                    AND deactivated_at IS NULL
                )
                OR
                (
                    active IS FALSE
                    AND deactivated_by IS NOT NULL
                    AND deactivated_at IS NOT NULL
                )
            )
            SQL
        );

        DB::statement(
            <<<'SQL'
            CREATE UNIQUE INDEX teacher_duty_occurrence_categories_school_code_unique
            ON teacher_duty_occurrence_categories (
                school_id,
                code
            )
            SQL
        );

        DB::statement(
            <<<'SQL'
            CREATE UNIQUE INDEX teacher_duty_occurrence_categories_school_id_id_unique
            ON teacher_duty_occurrence_categories (
                school_id,
                id
            )
            SQL
        );

        DB::statement(
            <<<'SQL'
            CREATE INDEX teacher_duty_occurrence_categories_school_active_idx
            ON teacher_duty_occurrence_categories (
                school_id,
                active
            )
            SQL
        );

        DB::statement(
            <<<'SQL'
            CREATE INDEX teacher_duty_occurrence_categories_school_display_order_idx
            ON teacher_duty_occurrence_categories (
                school_id,
                display_order
            )
            SQL
        );

        /*
         * Backfill the frozen canonical category set for every School that
         * already exists when this migration runs.
         *
         * Reserved-code collisions with non-canonical rows fail closed.
         * Existing valid canonical rows are preserved unchanged.
         */
        $canonicalCategories = [
            ['discipline', 'Discipline', 10],
            ['attendance', 'Attendance', 20],
            ['health_safety', 'Health & Safety', 30],
            ['cleanliness', 'Cleanliness', 40],
            ['property_facilities', 'Property & Facilities', 50],
            ['academic', 'Academic', 60],
            ['visitor_security', 'Visitor & Security', 70],
            ['general', 'General', 80],
        ];

        $canonicalCodes = array_column($canonicalCategories, 0);

        $invalidReservedCollision = DB::table('teacher_duty_occurrence_categories')
            ->whereIn('code', $canonicalCodes)
            ->where('is_canonical', false)
            ->exists();

        if ($invalidReservedCollision) {
            throw new RuntimeException(
                'Teacher Duty occurrence category provisioning aborted: reserved canonical code collision detected.'
            );
        }

        $schoolIds = DB::table('schools')
            ->orderBy('id')
            ->pluck('id');

        foreach ($schoolIds as $schoolId) {
            foreach ($canonicalCategories as [$code, $name, $displayOrder]) {
                $exists = DB::table('teacher_duty_occurrence_categories')
                    ->where('school_id', $schoolId)
                    ->where('code', $code)
                    ->where('is_canonical', true)
                    ->exists();

                if ($exists) {
                    continue;
                }

                DB::table('teacher_duty_occurrence_categories')->insert([
                    'id' => (string) Str::uuid(),
                    'school_id' => $schoolId,
                    'code' => $code,
                    'name' => $name,
                    'description' => null,
                    'is_canonical' => true,
                    'display_order' => $displayOrder,
                    'active' => true,
                    'created_by' => null,
                    'deactivated_by' => null,
                    'deactivated_at' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        Schema::create(
            'teacher_duty_occurrences',
            function (Blueprint $table): void {
                $table->uuid('id')->primary();

                $table->uuid('school_id');
                $table->uuid('duty_period_id');
                $table->uuid('occurrence_category_id');

                $table->date('occurrence_date');
                $table->time('occurrence_time')->nullable();

                $table->text('description');

                $table->uuid('recorded_by');

                $table->timestampTz('created_at')->useCurrent();
                $table->timestampTz('updated_at')->useCurrent();
            }
        );

        DB::statement(
            <<<'SQL'
            ALTER TABLE teacher_duty_occurrences
            ADD CONSTRAINT teacher_duty_occurrences_school_foreign
            FOREIGN KEY (school_id)
            REFERENCES schools (id)
            ON DELETE RESTRICT
            SQL
        );

        DB::statement(
            <<<'SQL'
            ALTER TABLE teacher_duty_occurrences
            ADD CONSTRAINT teacher_duty_occurrences_school_period_foreign
            FOREIGN KEY (school_id, duty_period_id)
            REFERENCES teacher_duty_periods (school_id, id)
            ON DELETE RESTRICT
            SQL
        );

        DB::statement(
            <<<'SQL'
            ALTER TABLE teacher_duty_occurrences
            ADD CONSTRAINT teacher_duty_occurrences_school_category_foreign
            FOREIGN KEY (school_id, occurrence_category_id)
            REFERENCES teacher_duty_occurrence_categories (school_id, id)
            ON DELETE RESTRICT
            SQL
        );

        DB::statement(
            <<<'SQL'
            ALTER TABLE teacher_duty_occurrences
            ADD CONSTRAINT teacher_duty_occurrences_school_recorded_by_foreign
            FOREIGN KEY (school_id, recorded_by)
            REFERENCES users (school_id, id)
            ON DELETE RESTRICT
            SQL
        );

        DB::statement(
            <<<'SQL'
            CREATE INDEX teacher_duty_occurrences_school_period_idx
            ON teacher_duty_occurrences (
                school_id,
                duty_period_id
            )
            SQL
        );

        DB::statement(
            <<<'SQL'
            CREATE INDEX teacher_duty_occurrences_school_date_idx
            ON teacher_duty_occurrences (
                school_id,
                occurrence_date
            )
            SQL
        );

        DB::statement(
            <<<'SQL'
            CREATE INDEX teacher_duty_occurrences_school_category_idx
            ON teacher_duty_occurrences (
                school_id,
                occurrence_category_id
            )
            SQL
        );

        DB::statement(
            <<<'SQL'
            CREATE INDEX teacher_duty_occurrences_period_date_order_idx
            ON teacher_duty_occurrences (
                school_id,
                duty_period_id,
                occurrence_date,
                occurrence_time,
                created_at,
                id
            )
            SQL
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('teacher_duty_occurrences');
        Schema::dropIfExists('teacher_duty_occurrence_categories');
    }
};
