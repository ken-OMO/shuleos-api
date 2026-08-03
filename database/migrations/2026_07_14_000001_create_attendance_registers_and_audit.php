<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_registers', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('school_id')->constrained('schools');
            $t->foreignUuid('attendance_session_id')->constrained('attendance_sessions');
            $t->foreignUuid('teacher_assignment_id')->nullable()->constrained('teacher_assignments');
            $t->foreignUuid('teacher_id')->constrained('teachers');
            $t->foreignUuid('grade_id')->constrained('grades');
            $t->foreignUuid('stream_id')->constrained('streams');
            $t->foreignUuid('academic_year_id')->constrained('academic_years');
            $t->foreignUuid('term_id')->constrained('terms');
            $t->date('attendance_date');
            $t->string('lesson_period')->nullable();
            $t->string('register_type');
            $t->string('status')->default('draft');
            $t->foreignUuid('opened_by')->constrained('users');
            $t->timestamp('opened_at');
            $t->foreignUuid('finalized_by')->nullable()->constrained('users');
            $t->timestamp('finalized_at')->nullable();
            $t->text('correction_reason')->nullable();
            $t->foreignUuid('corrected_by')->nullable()->constrained('users');
            $t->timestamp('corrected_at')->nullable();
            $t->timestamps();
            $t->boolean('is_deleted')->default(false);
            $t->timestamp('deleted_at')->nullable();
            $t->foreignUuid('deleted_by')->nullable()->constrained('users');
            $t->index(['school_id', 'attendance_date', 'stream_id', 'status']);
        });
        Schema::table('learner_attendance', function (Blueprint $t) {
            $t->foreignUuid('attendance_register_id')->nullable()->constrained('attendance_registers');
            $t->timestamp('marked_at')->nullable();
            $t->foreignUuid('updated_by')->nullable()->constrained('users');
            $t->text('correction_reason')->nullable();
            $t->unsignedInteger('is_late_minutes')->nullable();
            $t->string('source')->default('teacher');
            $t->boolean('finalized')->default(false);
            $t->unique(['attendance_register_id', 'learner_id']);
        });
        Schema::create('attendance_audit_logs', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('school_id')->constrained('schools');
            $t->foreignUuid('attendance_register_id')->constrained('attendance_registers');
            $t->foreignUuid('learner_attendance_id')->nullable()->constrained('learner_attendance');
            $t->foreignUuid('actor_user_id')->constrained('users');
            $t->string('action');
            $t->uuid('previous_status_id')->nullable();
            $t->uuid('new_status_id')->nullable();
            $t->text('previous_remarks')->nullable();
            $t->text('new_remarks')->nullable();
            $t->text('reason')->nullable();
            $t->jsonb('metadata')->nullable();
            $t->timestamp('created_at');
            $t->index(['school_id', 'attendance_register_id', 'action']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_audit_logs');
        Schema::table('learner_attendance', function (Blueprint $t) {
            $t->dropUnique(['attendance_register_id', 'learner_id']);
            $t->dropConstrainedForeignId('attendance_register_id');
            $t->dropConstrainedForeignId('updated_by');
            $t->dropColumn(['marked_at', 'correction_reason', 'is_late_minutes', 'source', 'finalized']);
        });
        Schema::dropIfExists('attendance_registers');
    }
};
