<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('timetable_entries', function (Blueprint $table) {
            $table->uuid('lesson_group_id')->nullable()->index();
            $table->unsignedTinyInteger('lesson_sequence')->nullable();
            $table->unsignedTinyInteger('lesson_span')->default(1);
            $table->boolean('is_locked')->default(false);
            $table->uuid('locked_by')->nullable();
            $table->timestamp('locked_at')->nullable();
            $table->text('lock_reason')->nullable();
            $table->uuid('generation_run_id')->nullable();
            $table->decimal('generation_score', 10, 2)->nullable();
        });
        Schema::table('timetables', function (Blueprint $table) {
            $table->uuid('parent_timetable_id')->nullable()->index();
            $table->uuid('copied_from_timetable_id')->nullable();
            $table->text('revision_reason')->nullable();
            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();
        });
        Schema::table('timetable_generation_runs', function (Blueprint $table) {
            $table->jsonb('parameters')->nullable();
            $table->integer('random_seed')->nullable();
            $table->unsignedInteger('required_lessons')->default(0);
            $table->unsignedInteger('scheduled_lessons')->default(0);
            $table->unsignedInteger('unscheduled_lessons')->default(0);
            $table->unsignedInteger('hard_conflicts')->default(0);
            $table->unsignedInteger('soft_warnings')->default(0);
            $table->decimal('score', 12, 2)->nullable();
            $table->jsonb('diagnostics')->nullable();
            $table->text('failed_reason')->nullable();
        });
        Schema::create('timetable_audit_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools');
            $table->foreignUuid('timetable_id')->constrained('timetables');
            $table->uuid('timetable_entry_id')->nullable();
            $table->uuid('generation_run_id')->nullable();
            $table->uuid('substitution_id')->nullable();
            $table->foreignUuid('actor_user_id')->constrained('users');
            $table->string('action');
            $table->jsonb('previous_values')->nullable();
            $table->jsonb('new_values')->nullable();
            $table->text('reason')->nullable();
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('timetable_audit_logs');
    }
};
