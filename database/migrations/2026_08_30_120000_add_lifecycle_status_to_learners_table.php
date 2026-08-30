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
        Schema::table('learners', function (Blueprint $table): void {
            $table->string('lifecycle_status', 20)
                ->nullable();

            $table->index(
                ['school_id', 'lifecycle_status'],
                'learners_school_lifecycle_status_index'
            );
        });

        /*
         * Existing active learners have an unambiguous lifecycle state.
         *
         * Existing inactive learners are deliberately left NULL because the
         * legacy boolean cannot tell us whether they withdrew, transferred,
         * graduated, or were made inactive for some other historical reason.
         */
        DB::table('learners')
            ->where('active', true)
            ->update([
                'lifecycle_status' => 'active',
            ]);

        DB::statement(
            <<<'SQL'
            ALTER TABLE learners
            ADD CONSTRAINT learners_lifecycle_status_check
            CHECK (
                lifecycle_status IS NULL
                OR lifecycle_status IN (
                    'active',
                    'withdrawn',
                    'transferred',
                    'graduated'
                )
            )
            SQL
        );

        /*
         * Preserve the compatibility invariant for all classified rows:
         *
         * lifecycle_status = active        => active = true
         * lifecycle_status = terminal      => active = false
         *
         * NULL remains permitted only for unclassified legacy records.
         */
        DB::statement(
            <<<'SQL'
            ALTER TABLE learners
            ADD CONSTRAINT learners_lifecycle_active_consistency_check
            CHECK (
                lifecycle_status IS NULL
                OR (
                    lifecycle_status = 'active'
                    AND active IS TRUE
                )
                OR (
                    lifecycle_status IN (
                        'withdrawn',
                        'transferred',
                        'graduated'
                    )
                    AND active IS FALSE
                )
            )
            SQL
        );
    }

    public function down(): void
    {
        DB::statement(
            'ALTER TABLE learners DROP CONSTRAINT IF EXISTS learners_lifecycle_active_consistency_check'
        );

        DB::statement(
            'ALTER TABLE learners DROP CONSTRAINT IF EXISTS learners_lifecycle_status_check'
        );

        Schema::table('learners', function (Blueprint $table): void {
            $table->dropIndex(
                'learners_school_lifecycle_status_index'
            );

            $table->dropColumn('lifecycle_status');
        });
    }
};
