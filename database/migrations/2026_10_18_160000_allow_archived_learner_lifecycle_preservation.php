<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            ALTER TABLE learners
            DROP CONSTRAINT learners_lifecycle_active_consistency_check,
            ADD CONSTRAINT learners_lifecycle_active_consistency_check
            CHECK (
                (is_deleted IS TRUE AND active IS FALSE)
                OR (
                    (
                        lifecycle_status IS NULL
                        OR (lifecycle_status = 'active' AND active IS TRUE)
                        OR (
                            lifecycle_status IN ('withdrawn', 'transferred', 'graduated')
                            AND active IS FALSE
                        )
                    )
                )
            )
            SQL);
    }

    public function down(): void
    {
        // Preserve retained lifecycle information rather than rewriting it.
        if (DB::table('learners')
            ->where('is_deleted', true)
            ->where('lifecycle_status', 'active')
            ->where('active', false)
            ->exists()) {
            throw new RuntimeException(
                'Cannot restore the old lifecycle constraint while archived learners retain an active lifecycle status.'
            );
        }

        DB::statement(<<<'SQL'
            ALTER TABLE learners
            DROP CONSTRAINT learners_lifecycle_active_consistency_check,
            ADD CONSTRAINT learners_lifecycle_active_consistency_check
            CHECK (
                lifecycle_status IS NULL
                OR (lifecycle_status = 'active' AND active IS TRUE)
                OR (
                    lifecycle_status IN ('withdrawn', 'transferred', 'graduated')
                    AND active IS FALSE
                )
            )
            SQL);
    }
};
