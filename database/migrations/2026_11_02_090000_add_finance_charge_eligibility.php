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
        DB::statement("SET LOCAL lock_timeout = '5s'");

        Schema::table('fee_structures', function (Blueprint $table): void {
            $table->string('charge_target', 20)->default('all');
            $table->boolean('requires_participation')->default(false);
        });

        DB::statement("ALTER TABLE fee_structures
            ADD CONSTRAINT fee_structure_charge_target_check
            CHECK (charge_target IN ('all', 'day_scholar', 'boarder'))");

        DB::statement('DROP INDEX public.f611_structure_scope');
        DB::statement("CREATE UNIQUE INDEX f611_structure_scope ON fee_structures
            (school_id, academic_year_id, term_id, grade_id, stream_id,
             fee_category_id, charge_target)
            NULLS NOT DISTINCT
            WHERE is_deleted = false AND status IN ('draft', 'approved', 'active')");

        Schema::create('fee_charge_participations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('school_id');
            $table->uuid('learner_id');
            $table->uuid('fee_category_id');
            $table->uuid('academic_year_id');
            $table->uuid('term_id');
            $table->boolean('active');
            $table->uuid('updated_by');
            $table->timestamps();
            $table->unique(
                ['school_id', 'learner_id', 'fee_category_id', 'academic_year_id', 'term_id'],
                'fee_charge_participation_scope_unique'
            );
            $table->foreign('school_id')->references('id')->on('schools');
            $table->foreign(['school_id', 'learner_id'], 'charge_participation_learner_fk')
                ->references(['school_id', 'id'])->on('learners');
            $table->foreign(['school_id', 'fee_category_id'], 'charge_participation_category_fk')
                ->references(['school_id', 'id'])->on('fee_categories');
            $table->foreign(
                ['school_id', 'academic_year_id', 'term_id'],
                'charge_participation_calendar_fk'
            )->references(['school_id', 'academic_year_id', 'id'])->on('terms');
            $table->foreign(['school_id', 'updated_by'], 'charge_participation_actor_fk')
                ->references(['school_id', 'id'])->on('users');
        });
    }

    public function down(): void
    {
        if (DB::table('fee_charge_participations')->exists()
            || DB::table('fee_structures')
                ->where(fn ($query) => $query
                    ->where('charge_target', '!=', 'all')
                    ->orWhere('requires_participation', true))->exists()) {
            throw new RuntimeException('Retain charge eligibility data; use a reviewed forward migration.');
        }

        Schema::drop('fee_charge_participations');
        DB::statement('DROP INDEX public.f611_structure_scope');
        DB::statement('ALTER TABLE fee_structures DROP CONSTRAINT fee_structure_charge_target_check');

        Schema::table('fee_structures', function (Blueprint $table): void {
            $table->dropColumn(['charge_target', 'requires_participation']);
        });

        DB::statement("CREATE UNIQUE INDEX f611_structure_scope ON fee_structures
            (school_id, academic_year_id, term_id, grade_id, stream_id, fee_category_id)
            NULLS NOT DISTINCT
            WHERE is_deleted = false AND status IN ('draft', 'approved', 'active')");
    }
};
