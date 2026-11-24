<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('co_curricular_groups', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools');
            $table->string('kind', 16);
            $table->string('name', 120);
            $table->timestampTz('archived_at')->nullable();
            $table->timestampsTz();
            $table->unique(['id', 'school_id', 'kind']);
        });
        DB::statement("ALTER TABLE co_curricular_groups ADD CHECK (kind IN ('club', 'house', 'society'))");
        DB::statement('CREATE UNIQUE INDEX co_curricular_active_name_unique ON co_curricular_groups (school_id, kind, lower(name)) WHERE archived_at IS NULL');

        Schema::create('co_curricular_placements', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('school_id');
            $table->uuid('learner_id');
            $table->foreign(['school_id', 'learner_id'])->references(['school_id', 'id'])->on('learners');
            $table->uuid('group_id');
            $table->string('kind', 16);
            $table->timestampTz('started_at');
            $table->timestampTz('ended_at')->nullable();
            $table->foreign(['group_id', 'school_id', 'kind'], 'co_curricular_placements_group_fk')
                ->references(['id', 'school_id', 'kind'])->on('co_curricular_groups');
        });
        DB::statement('CREATE UNIQUE INDEX co_curricular_current_placement_unique ON co_curricular_placements (learner_id, kind) WHERE ended_at IS NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('co_curricular_placements');
        Schema::dropIfExists('co_curricular_groups');
    }
};
