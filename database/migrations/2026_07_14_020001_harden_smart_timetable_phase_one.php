<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('timetable_days', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools');
            $table->foreignUuid('timetable_profile_id')->constrained('timetable_profiles');
            $table->unsignedTinyInteger('day_of_week');
            $table->string('display_name');
            $table->unsignedTinyInteger('day_order');
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->unique(['timetable_profile_id', 'day_of_week']);
            $table->unique(['timetable_profile_id', 'day_order']);
        });

        Schema::table('timetables', function (Blueprint $table) {
            $table->unsignedInteger('version')->default(1);
            $table->uuid('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->uuid('published_by')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->jsonb('validation_summary')->nullable();
            $table->timestamp('validated_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->boolean('is_deleted')->default(false);
            $table->timestamp('deleted_at')->nullable();
            $table->uuid('deleted_by')->nullable();
        });

        Schema::table('timetable_entries', function (Blueprint $table) {
            $table->foreignUuid('school_id')->nullable()->constrained('schools');
            $table->foreignUuid('teacher_assignment_id')->nullable()->constrained('teacher_assignments');
            $table->foreignUuid('timetable_day_id')->nullable()->constrained('timetable_days');
            $table->string('entry_status')->default('draft');
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->boolean('is_deleted')->default(false);
            $table->timestamp('deleted_at')->nullable();
            $table->uuid('deleted_by')->nullable();
            $table->index(['timetable_id', 'day_of_week', 'period_id']);
        });

        Schema::table('timetable_constraints', function (Blueprint $table) {
            $table->string('constraint_type')->nullable();
            $table->string('scope_type')->nullable();
            $table->uuid('scope_id')->nullable();
            $table->boolean('is_hard')->default(true);
            $table->jsonb('configuration')->nullable();
        });

        Schema::table('timetable_conflicts', function (Blueprint $table) {
            $table->jsonb('metadata')->nullable();
            $table->timestamp('detected_at')->nullable();
            $table->uuid('resolved_by')->nullable();
        });

        Schema::table('timetable_substitutions', function (Blueprint $table) {
            $table->string('status')->default('pending');
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->uuid('cancelled_by')->nullable();
            $table->text('cancellation_reason')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('timetable_substitutions', fn (Blueprint $table) => $table->dropColumn(['status', 'approved_at', 'cancelled_at', 'cancelled_by', 'cancellation_reason']));
        Schema::table('timetable_conflicts', fn (Blueprint $table) => $table->dropColumn(['metadata', 'detected_at', 'resolved_by']));
        Schema::table('timetable_constraints', fn (Blueprint $table) => $table->dropColumn(['constraint_type', 'scope_type', 'scope_id', 'is_hard', 'configuration']));
        Schema::table('timetable_entries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('school_id');
            $table->dropConstrainedForeignId('teacher_assignment_id');
            $table->dropConstrainedForeignId('timetable_day_id');
            $table->dropColumn(['entry_status', 'created_by', 'updated_by', 'updated_at', 'is_deleted', 'deleted_at', 'deleted_by']);
        });
        Schema::table('timetables', fn (Blueprint $table) => $table->dropColumn(['version', 'approved_by', 'approved_at', 'published_by', 'published_at', 'archived_at', 'validation_summary', 'validated_at', 'updated_at', 'is_deleted', 'deleted_at', 'deleted_by']));
        Schema::dropIfExists('timetable_days');
    }
};
