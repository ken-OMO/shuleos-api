<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('homework_submissions', function (Blueprint $table) {
            $table->unsignedInteger('version')->default(1);
            $table->timestamp('autosaved_at')->nullable();
            $table->timestamp('withdrawn_at')->nullable();
        });
        Schema::table('learner_dashboard_preferences', function (Blueprint $table) {
            $table->string('preferred_language', 12)->default('en');
            $table->string('display_name', 120)->nullable();
            $table->uuid('profile_image_attachment_id')->nullable();
            $table->string('timezone', 64)->default('Africa/Nairobi');
            $table->jsonb('dashboard_widgets')->nullable();
            $table->jsonb('notification_preferences')->nullable();
            $table->jsonb('accessibility_preferences')->nullable();
            $table->string('digest_frequency', 20)->default('daily');
            $table->time('quiet_hours_start')->nullable();
            $table->time('quiet_hours_end')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->unsignedInteger('version')->default(1);
        });

        Schema::create('learner_portal_devices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools');
            $table->foreignUuid('user_id')->constrained('users');
            $table->foreignUuid('learner_id')->constrained('learners');
            $table->string('device_identifier_hash', 64);
            $table->text('push_token_encrypted')->nullable();
            $table->string('platform', 20);
            $table->string('device_name')->nullable();
            $table->string('app_version', 40)->nullable();
            $table->boolean('push_enabled')->default(false);
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
            $table->unique(['school_id', 'user_id', 'device_identifier_hash'], 'learner_device_owner_hash_unique');
            $table->index(['school_id', 'learner_id', 'revoked_at']);
        });

        Schema::create('learner_portal_attachments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools');
            $table->foreignUuid('user_id')->constrained('users');
            $table->foreignUuid('learner_id')->constrained('learners');
            $table->string('context_type', 40);
            $table->uuid('context_id')->nullable();
            $table->string('original_filename');
            $table->string('safe_filename');
            $table->string('mime_type', 150);
            $table->string('extension', 20);
            $table->unsignedBigInteger('source_size');
            $table->string('source_hash', 64);
            $table->string('stored_hash', 64);
            $table->uuid('storage_id');
            $table->string('status', 30)->default('pending_scan');
            $table->timestamp('attached_at')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
            $table->index(['school_id', 'learner_id', 'context_type']);
            $table->index(['user_id', 'source_hash']);
        });

        Schema::create('learner_offline_resources', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools');
            $table->foreignUuid('learner_id')->constrained('learners');
            $table->foreignUuid('resource_id')->constrained('learning_resources');
            $table->unsignedInteger('resource_version');
            $table->timestamp('available_offline_at');
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
            $table->unique(['learner_id', 'resource_id']);
        });

        Schema::create('learner_sync_operations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools');
            $table->foreignUuid('user_id')->constrained('users');
            $table->foreignUuid('learner_id')->constrained('learners');
            $table->foreignUuid('device_id')->constrained('learner_portal_devices');
            $table->uuid('operation_uuid');
            $table->string('entity_type', 40);
            $table->uuid('entity_id');
            $table->string('operation', 20);
            $table->unsignedInteger('base_version');
            $table->string('status', 20);
            $table->unsignedInteger('server_version');
            $table->timestamp('created_at');
            $table->unique(['user_id', 'operation_uuid']);
            $table->index(['school_id', 'learner_id', 'created_at']);
        });

        Schema::create('learner_sync_conflicts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools');
            $table->foreignUuid('user_id')->constrained('users');
            $table->foreignUuid('learner_id')->constrained('learners');
            $table->foreignUuid('device_id')->constrained('learner_portal_devices');
            $table->uuid('operation_uuid');
            $table->string('entity_type', 40);
            $table->uuid('entity_id');
            $table->unsignedInteger('client_version');
            $table->unsignedInteger('server_version');
            $table->jsonb('safe_server_record');
            $table->string('status', 20)->default('open');
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'status']);
        });

        Schema::create('learner_push_deliveries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools');
            $table->foreignUuid('user_id')->constrained('users');
            $table->foreignUuid('learner_id')->constrained('learners');
            $table->foreignUuid('device_id')->constrained('learner_portal_devices');
            $table->string('category', 50);
            $table->string('title', 150);
            $table->string('body', 300);
            $table->string('deep_link')->nullable();
            $table->string('idempotency_key', 180);
            $table->string('status', 30)->default('queued');
            $table->string('provider', 30)->default('log');
            $table->string('provider_message_id')->nullable();
            $table->string('failure_code')->nullable();
            $table->timestamp('queued_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamps();
            $table->unique(['device_id', 'idempotency_key']);
        });

        Schema::create('learner_help_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools');
            $table->foreignUuid('learner_id')->constrained('learners');
            $table->foreignUuid('created_by')->constrained('users');
            $table->string('category', 40);
            $table->string('subject', 160);
            $table->text('message');
            $table->string('priority', 20)->default('normal');
            $table->string('status', 30)->default('submitted');
            $table->string('destination_role', 40);
            $table->timestamp('submitted_at');
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
            $table->index(['school_id', 'learner_id', 'created_at']);
        });

        Schema::create('learner_help_request_history', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools');
            $table->foreignUuid('help_request_id')->constrained('learner_help_requests');
            $table->foreignUuid('actor_user_id')->constrained('users');
            $table->string('action', 40);
            $table->jsonb('safe_metadata')->nullable();
            $table->timestamp('created_at');
            $table->index(['help_request_id', 'created_at']);
        });

        Schema::create('learner_portal_audit_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools');
            $table->foreignUuid('learner_id')->constrained('learners');
            $table->foreignUuid('actor_user_id')->constrained('users');
            $table->string('action', 80);
            $table->string('entity_type', 60)->nullable();
            $table->uuid('entity_id')->nullable();
            $table->jsonb('safe_metadata')->nullable();
            $table->timestamp('created_at');
            $table->index(['school_id', 'learner_id', 'created_at']);
        });
    }

    public function down(): void
    {
        foreach (['learner_portal_audit_logs', 'learner_help_request_history', 'learner_help_requests', 'learner_push_deliveries', 'learner_sync_conflicts', 'learner_sync_operations', 'learner_offline_resources', 'learner_portal_attachments', 'learner_portal_devices'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('learner_dashboard_preferences', fn (Blueprint $table) => $table->dropColumn(['preferred_language', 'display_name', 'profile_image_attachment_id', 'timezone', 'dashboard_widgets', 'notification_preferences', 'accessibility_preferences', 'digest_frequency', 'quiet_hours_start', 'quiet_hours_end', 'last_synced_at', 'version']));
        Schema::table('homework_submissions', fn (Blueprint $table) => $table->dropColumn(['version', 'autosaved_at', 'withdrawn_at']));
    }
};
