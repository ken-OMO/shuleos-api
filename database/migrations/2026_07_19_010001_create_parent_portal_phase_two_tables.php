<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('parent_portal_devices', fn (Blueprint $table) => $table->unsignedInteger('version')->default(1));
        Schema::table('communication_preferences', fn (Blueprint $table) => $table->unsignedInteger('version')->default(1));

        Schema::create('parent_payment_attempts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools');
            $table->foreignUuid('parent_user_id')->constrained('users');
            $table->foreignUuid('learner_id')->constrained('learners');
            $table->uuid('invoice_id')->nullable();
            $table->uuid('payment_id')->nullable();
            $table->string('payment_reference', 80);
            $table->string('idempotency_key_hash', 64);
            $table->string('provider', 30);
            $table->string('provider_request_id')->nullable();
            $table->string('checkout_request_id')->nullable();
            $table->string('merchant_request_id')->nullable();
            $table->string('phone_hash', 64);
            $table->string('phone_masked', 20);
            $table->unsignedBigInteger('amount_minor');
            $table->string('currency', 3)->default('KES');
            $table->string('status', 40)->default('pending');
            $table->string('failure_code')->nullable();
            $table->string('safe_failure_message')->nullable();
            $table->timestamp('initiated_at');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('expired_at')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->unique(['school_id', 'parent_user_id', 'idempotency_key_hash'], 'parent_payment_idempotency_unique');
            $table->unique(['provider', 'checkout_request_id']);
            $table->unique(['school_id', 'payment_reference']);
            $table->index(['school_id', 'parent_user_id', 'status']);
        });
        Schema::create('parent_payment_attempt_history', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools');
            $table->foreignUuid('payment_attempt_id')->constrained('parent_payment_attempts');
            $table->string('from_status', 40)->nullable();
            $table->string('to_status', 40);
            $table->string('safe_reason')->nullable();
            $table->uuid('actor_user_id')->nullable();
            $table->timestamp('created_at');
            $table->index(['payment_attempt_id', 'created_at']);
        });
        Schema::create('parent_payment_callback_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('provider', 30);
            $table->string('event_key', 180);
            $table->uuid('payment_attempt_id')->nullable();
            $table->string('status', 30);
            $table->jsonb('redacted_payload')->nullable();
            $table->timestamp('received_at');
            $table->timestamp('processed_at')->nullable();
            $table->unique(['provider', 'event_key']);
        });
        Schema::create('parent_push_deliveries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools');
            $table->foreignUuid('user_id')->constrained('users');
            $table->foreignUuid('device_id')->constrained('parent_portal_devices');
            $table->string('category', 50);
            $table->string('title', 150);
            $table->string('body', 240);
            $table->string('deep_link')->nullable();
            $table->string('idempotency_key', 180);
            $table->string('status', 30)->default('queued');
            $table->string('provider', 30)->default('log');
            $table->string('provider_message_id')->nullable();
            $table->string('failure_code')->nullable();
            $table->unsignedInteger('attempt_count')->default(0);
            $table->timestamp('queued_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamps();
            $table->unique(['device_id', 'idempotency_key']);
        });
        Schema::create('parent_conversations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools');
            $table->foreignUuid('parent_user_id')->constrained('users');
            $table->foreignUuid('learner_id')->constrained('learners');
            $table->string('conversation_type', 40);
            $table->string('subject', 160);
            $table->string('destination_role', 50);
            $table->uuid('resolved_staff_user_id')->nullable();
            $table->string('status', 20)->default('open');
            $table->boolean('safeguarding_restricted')->default(false);
            $table->unsignedInteger('version')->default(1);
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
            $table->index(['school_id', 'parent_user_id', 'status']);
        });
        Schema::create('parent_conversation_messages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools');
            $table->foreignUuid('conversation_id')->constrained('parent_conversations');
            $table->foreignUuid('sender_user_id')->constrained('users');
            $table->string('sender_type', 20);
            $table->text('message');
            $table->timestamp('sent_at');
            $table->timestamp('created_at');
            $table->index(['conversation_id', 'sent_at']);
        });
        Schema::create('parent_consents', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools');
            $table->string('category', 60);
            $table->string('title', 180);
            $table->text('consent_text');
            $table->unsignedInteger('consent_version')->default(1);
            $table->string('status', 20)->default('draft');
            $table->boolean('reason_required_on_decline')->default(false);
            $table->timestamp('published_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('withdrawn_at')->nullable();
            $table->timestamps();
            $table->index(['school_id', 'status', 'expires_at']);
        });
        Schema::create('parent_consent_audiences', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools');
            $table->foreignUuid('consent_id')->constrained('parent_consents');
            $table->foreignUuid('learner_id')->constrained('learners');
            $table->unique(['consent_id', 'learner_id']);
        });
        Schema::create('parent_consent_responses', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools');
            $table->foreignUuid('consent_id')->constrained('parent_consents');
            $table->foreignUuid('parent_user_id')->constrained('users');
            $table->foreignUuid('learner_id')->constrained('learners');
            $table->unsignedInteger('consent_version');
            $table->string('response', 20);
            $table->text('reason')->nullable();
            $table->timestamp('responded_at');
            $table->timestamps();
            $table->unique(['consent_id', 'parent_user_id', 'learner_id']);
        });
        Schema::create('parent_appointments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools');
            $table->foreignUuid('parent_user_id')->constrained('users');
            $table->foreignUuid('learner_id')->constrained('learners');
            $table->string('category', 40);
            $table->uuid('resolved_staff_user_id')->nullable();
            $table->timestamp('preferred_from');
            $table->timestamp('preferred_to');
            $table->timestamp('proposed_at')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->text('reason');
            $table->string('status', 20)->default('requested');
            $table->text('meeting_link_encrypted')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->index(['school_id', 'parent_user_id', 'status']);
        });
        Schema::create('parent_appointment_history', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools');
            $table->foreignUuid('appointment_id')->constrained('parent_appointments');
            $table->uuid('actor_user_id');
            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20);
            $table->text('safe_reason')->nullable();
            $table->timestamp('created_at');
        });
        Schema::create('parent_sync_operations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools');
            $table->foreignUuid('user_id')->constrained('users');
            $table->foreignUuid('device_id')->constrained('parent_portal_devices');
            $table->uuid('operation_uuid');
            $table->string('entity_type', 50);
            $table->uuid('entity_id');
            $table->unsignedInteger('base_version');
            $table->string('status', 20);
            $table->unsignedInteger('server_version');
            $table->timestamp('created_at');
            $table->unique(['user_id', 'operation_uuid']);
        });
        Schema::create('parent_sync_conflicts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools');
            $table->foreignUuid('user_id')->constrained('users');
            $table->foreignUuid('device_id')->constrained('parent_portal_devices');
            $table->uuid('operation_uuid');
            $table->string('entity_type', 50);
            $table->uuid('entity_id');
            $table->unsignedInteger('client_version');
            $table->unsignedInteger('server_version');
            $table->jsonb('safe_server_state');
            $table->string('status', 20)->default('open');
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'status']);
        });
        Schema::create('parent_portal_attachments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools');
            $table->foreignUuid('user_id')->constrained('users');
            $table->string('context_type', 50);
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
            $table->index(['school_id', 'user_id', 'context_type']);
        });
    }

    public function down(): void
    {
        foreach (['parent_portal_attachments', 'parent_sync_conflicts', 'parent_sync_operations', 'parent_appointment_history', 'parent_appointments', 'parent_consent_responses', 'parent_consent_audiences', 'parent_consents', 'parent_conversation_messages', 'parent_conversations', 'parent_push_deliveries', 'parent_payment_callback_events', 'parent_payment_attempt_history', 'parent_payment_attempts'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('communication_preferences', fn (Blueprint $table) => $table->dropColumn('version'));
        Schema::table('parent_portal_devices', fn (Blueprint $table) => $table->dropColumn('version'));
    }
};
