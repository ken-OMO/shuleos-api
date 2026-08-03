<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('teacher_workflows', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('school_id')->constrained('schools');
            $t->string('entity_type', 40);
            $t->uuid('entity_id');
            $t->foreignUuid('teacher_id')->constrained('teachers');
            $t->foreignUuid('teacher_assignment_id')->nullable()->constrained('teacher_assignments');
            $t->string('state', 30)->default('draft');
            $t->unsignedInteger('revision_number')->default(1);
            $t->unsignedInteger('version')->default(1);
            $t->uuid('submitted_by')->nullable();
            $t->timestamp('submitted_at')->nullable();
            $t->uuid('reviewed_by')->nullable();
            $t->timestamp('reviewed_at')->nullable();
            $t->text('review_reason')->nullable();
            $t->jsonb('approved_snapshot')->nullable();
            $t->timestamps();
            $t->unique(['school_id', 'entity_type', 'entity_id']);
            $t->index(['school_id', 'state', 'entity_type']);
            $t->index(['teacher_id', 'state']);
        });

        Schema::create('teacher_workflow_history', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('school_id')->constrained('schools');
            $t->foreignUuid('workflow_id')->constrained('teacher_workflows');
            $t->uuid('actor_user_id');
            $t->string('from_state', 30);
            $t->string('to_state', 30);
            $t->text('reason')->nullable();
            $t->unsignedInteger('version');
            $t->jsonb('safe_metadata')->nullable();
            $t->timestamp('created_at');
            $t->index(['workflow_id', 'created_at']);
        });

        Schema::create('mark_entry_batches', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('school_id')->constrained('schools');
            $t->foreignUuid('exam_id')->constrained('exams');
            $t->foreignUuid('exam_paper_id')->constrained('exam_papers');
            $t->foreignUuid('teacher_assignment_id')->constrained('teacher_assignments');
            $t->uuid('teacher_id');
            $t->uuid('entered_by');
            $t->string('status', 30)->default('draft');
            $t->unsignedInteger('expected_learner_count');
            $t->unsignedInteger('entered_count')->default(0);
            $t->unsignedInteger('version')->default(1);
            $t->timestamp('submitted_at')->nullable();
            $t->timestamp('moderated_at')->nullable();
            $t->uuid('moderated_by')->nullable();
            $t->timestamp('locked_at')->nullable();
            $t->text('review_reason')->nullable();
            $t->timestamps();
            $t->unique(['school_id', 'exam_paper_id', 'teacher_assignment_id']);
            $t->index(['school_id', 'status']);
        });

        Schema::create('mark_entry_batch_items', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('school_id')->constrained('schools');
            $t->foreignUuid('batch_id')->constrained('mark_entry_batches');
            $t->foreignUuid('learner_id')->constrained('learners');
            $t->uuid('exam_result_id')->nullable();
            $t->decimal('marks', 8, 2);
            $t->decimal('previous_marks', 8, 2)->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
            $t->unique(['batch_id', 'learner_id']);
        });

        Schema::create('mark_correction_requests', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('school_id')->constrained('schools');
            $t->foreignUuid('batch_id')->constrained('mark_entry_batches');
            $t->foreignUuid('batch_item_id')->nullable()->constrained('mark_entry_batch_items');
            $t->uuid('requested_by');
            $t->text('reason');
            $t->decimal('previous_marks', 8, 2)->nullable();
            $t->decimal('proposed_marks', 8, 2)->nullable();
            $t->string('status', 30)->default('pending');
            $t->uuid('decided_by')->nullable();
            $t->timestamp('decided_at')->nullable();
            $t->text('decision_reason')->nullable();
            $t->timestamps();
            $t->index(['school_id', 'status']);
        });

        Schema::create('teacher_sync_operations', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('school_id')->constrained('schools');
            $t->uuid('user_id');
            $t->foreignUuid('device_id')->constrained('teacher_portal_devices');
            $t->uuid('operation_uuid');
            $t->string('entity_type', 40);
            $t->uuid('entity_id');
            $t->string('operation', 20);
            $t->unsignedInteger('base_version');
            $t->string('status', 30);
            $t->unsignedInteger('server_version')->nullable();
            $t->timestamp('created_at');
            $t->unique(['user_id', 'operation_uuid']);
        });

        Schema::create('teacher_sync_conflicts', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('school_id')->constrained('schools');
            $t->uuid('user_id');
            $t->foreignUuid('device_id')->constrained('teacher_portal_devices');
            $t->uuid('operation_uuid');
            $t->string('entity_type', 40);
            $t->uuid('entity_id');
            $t->unsignedInteger('client_version');
            $t->unsignedInteger('server_version');
            $t->jsonb('safe_server_record');
            $t->string('status', 20)->default('open');
            $t->timestamp('resolved_at')->nullable();
            $t->timestamps();
            $t->index(['user_id', 'status']);
        });

        Schema::create('teacher_sync_cursors', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('school_id')->constrained('schools');
            $t->uuid('user_id');
            $t->foreignUuid('device_id')->constrained('teacher_portal_devices');
            $t->string('cursor', 100);
            $t->timestamp('last_pulled_at')->nullable();
            $t->timestamps();
            $t->unique(['user_id', 'device_id']);
        });

        Schema::create('teacher_attachments', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('school_id')->constrained('schools');
            $t->uuid('user_id');
            $t->uuid('teacher_id');
            $t->string('context_type', 40);
            $t->uuid('context_id')->nullable();
            $t->string('original_filename');
            $t->string('safe_filename');
            $t->string('mime_type', 150);
            $t->string('extension', 20);
            $t->unsignedBigInteger('source_size');
            $t->string('source_hash', 64);
            $t->string('stored_hash', 64);
            $t->string('storage_id', 64);
            $t->string('status', 30)->default('pending_scan');
            $t->text('rejection_reason')->nullable();
            $t->timestamps();
            $t->index(['school_id', 'user_id', 'status']);
            $t->index(['context_type', 'context_id']);
            $t->index(['school_id', 'source_hash']);
        });

        Schema::create('teacher_push_deliveries', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('school_id')->constrained('schools');
            $t->uuid('user_id');
            $t->foreignUuid('device_id')->constrained('teacher_portal_devices');
            $t->string('category', 60);
            $t->string('title', 150);
            $t->string('body', 500);
            $t->string('deep_link', 500)->nullable();
            $t->string('idempotency_key', 100);
            $t->string('status', 30)->default('pending');
            $t->string('provider', 30)->default('log');
            $t->string('provider_message_id')->nullable();
            $t->unsignedSmallInteger('attempt_count')->default(0);
            $t->timestamp('queued_at')->nullable();
            $t->timestamp('accepted_at')->nullable();
            $t->timestamp('failed_at')->nullable();
            $t->string('failure_code', 80)->nullable();
            $t->timestamps();
            $t->unique(['device_id', 'idempotency_key']);
            $t->index(['school_id', 'status']);
        });

        Schema::create('teacher_push_delivery_attempts', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('delivery_id')->constrained('teacher_push_deliveries');
            $t->string('status', 30);
            $t->string('failure_code', 80)->nullable();
            $t->timestamp('created_at');
            $t->index(['delivery_id', 'created_at']);
        });

        foreach (['lesson_plans', 'lesson_notes', 'records_of_work', 'homework_assignments', 'teacher_dashboard_preferences'] as $table) {
            if (Schema::hasTable($table) && ! Schema::hasColumn($table, 'sync_version')) {
                Schema::table($table, fn (Blueprint $t) => $t->unsignedInteger('sync_version')->default(1));
            }
        }
    }

    public function down(): void {}
};
