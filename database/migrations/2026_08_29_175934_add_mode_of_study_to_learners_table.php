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
            $table->string('mode_of_study', 20)
                ->nullable()
                ->after('stream_id');

            $table->index(
                ['school_id', 'mode_of_study'],
                'learners_school_mode_of_study_index'
            );
        });

        DB::statement(
            <<<'SQL'
            ALTER TABLE learners
            ADD CONSTRAINT learners_mode_of_study_check
            CHECK (
                mode_of_study IS NULL
                OR mode_of_study IN ('day_scholar', 'boarder')
            )
            SQL
        );
    }

    public function down(): void
    {
        DB::statement(
            'ALTER TABLE learners DROP CONSTRAINT IF EXISTS learners_mode_of_study_check'
        );

        Schema::table('learners', function (Blueprint $table): void {
            $table->dropIndex('learners_school_mode_of_study_index');
            $table->dropColumn('mode_of_study');
        });
    }
};
