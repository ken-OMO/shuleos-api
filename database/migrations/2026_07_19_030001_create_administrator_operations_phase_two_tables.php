<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('administrator_operation_previews', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->nullable()->constrained('schools');
            $table->foreignUuid('user_id')->constrained('users');
            $table->string('operation', 60);
            $table->string('scope_type', 20);
            $table->string('request_hash', 64);
            $table->jsonb('safe_summary');
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->timestamp('created_at');
            $table->index(['user_id', 'operation', 'expires_at']);
        });
        Schema::create('administrator_feature_flags', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('key', 100);
            $table->text('description');
            $table->string('scope_type', 20);
            $table->uuid('scope_id')->nullable();
            $table->boolean('enabled')->default(false);
            $table->unsignedSmallInteger('rollout_percentage')->nullable();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->string('status', 20)->default('active');
            $table->foreignUuid('created_by')->constrained('users');
            $table->foreignUuid('updated_by')->constrained('users');
            $table->timestamps();
            $table->unique(['key', 'scope_type', 'scope_id']);
        });
        Schema::create('administrator_feature_flag_history', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('flag_id')->constrained('administrator_feature_flags');
            $table->foreignUuid('actor_user_id')->constrained('users');
            $table->string('action', 30);
            $table->jsonb('safe_snapshot');
            $table->timestamp('created_at');
            $table->index(['flag_id', 'created_at']);
        });
        Schema::create('administrator_maintenance_windows', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->nullable()->constrained('schools');
            $table->string('scope_type', 20);
            $table->string('status', 20);
            $table->text('reason');
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->foreignUuid('created_by')->constrained('users');
            $table->foreignUuid('activated_by')->nullable()->constrained('users');
            $table->foreignUuid('completed_by')->nullable()->constrained('users');
            $table->timestamps();
            $table->index(['scope_type', 'school_id', 'status']);
        });
        Schema::create('administrator_maintenance_history', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('maintenance_id')->constrained('administrator_maintenance_windows');
            $table->foreignUuid('actor_user_id')->constrained('users');
            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20);
            $table->text('safe_reason')->nullable();
            $table->timestamp('created_at');
        });
        Schema::create('administrator_provider_configurations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->nullable()->constrained('schools');
            $table->string('scope_type', 20);
            $table->string('category', 30);
            $table->string('provider', 60);
            $table->text('configuration_encrypted')->nullable();
            $table->boolean('secret_present')->default(false);
            $table->boolean('enabled')->default(false);
            $table->unsignedInteger('version')->default(1);
            $table->timestamp('rotated_at')->nullable();
            $table->foreignUuid('updated_by')->constrained('users');
            $table->timestamps();
            $table->unique(['scope_type', 'school_id', 'category']);
        });
        Schema::create('administrator_provider_configuration_history', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('configuration_id');
            $table->foreignUuid('actor_user_id')->constrained('users');
            $table->string('action', 30);
            $table->string('provider', 60);
            $table->boolean('secret_present');
            $table->timestamp('created_at');
        });
        Schema::create('administrator_scheduler_heartbeats', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('task_key', 100)->unique();
            $table->string('status', 20);
            $table->timestamp('last_started_at')->nullable();
            $table->timestamp('last_completed_at')->nullable();
            $table->timestamp('last_failed_at')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->string('safe_message')->nullable();
            $table->uuid('active_run_id')->nullable();
            $table->timestamps();
        });
        Schema::create('administrator_scheduler_runs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('task_key', 100);
            $table->string('status', 20);
            $table->foreignUuid('requested_by')->nullable()->constrained('users');
            $table->boolean('manual')->default(false);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->string('safe_message')->nullable();
            $table->timestamps();
            $table->index(['task_key', 'status']);
        });
        Schema::create('administrator_storage_records', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools');
            $table->string('record_type', 40);
            $table->uuid('record_id')->nullable();
            $table->string('status', 30);
            $table->string('scanner_state', 30);
            $table->string('safe_label');
            $table->unsignedBigInteger('size')->default(0);
            $table->string('storage_reference_hash', 64);
            $table->foreignUuid('reviewed_by')->nullable()->constrained('users');
            $table->text('review_reason')->nullable();
            $table->timestamps();
            $table->index(['school_id', 'status']);
        });
        Schema::create('administrator_backups', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->nullable()->constrained('schools');
            $table->string('scope_type', 20);
            $table->string('backup_type', 40);
            $table->string('status', 20);
            $table->boolean('tooling_available')->default(false);
            $table->string('checksum', 64)->nullable();
            $table->unsignedBigInteger('size')->nullable();
            $table->jsonb('safe_manifest')->nullable();
            $table->string('failure_code', 60)->nullable();
            $table->timestamp('retention_until')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->foreignUuid('requested_by')->constrained('users');
            $table->foreignUuid('verified_by')->nullable()->constrained('users');
            $table->timestamps();
            $table->index(['scope_type', 'school_id', 'status']);
        });
        Schema::create('administrator_restores', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('backup_id')->constrained('administrator_backups');
            $table->foreignUuid('pre_restore_backup_id')->nullable()->constrained('administrator_backups');
            $table->string('status', 30);
            $table->text('reason');
            $table->boolean('dry_run')->default(true);
            $table->boolean('execution_enabled')->default(false);
            $table->foreignUuid('requested_by')->constrained('users');
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();
        });
        Schema::create('administrator_api_keys', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->nullable()->constrained('schools');
            $table->string('scope_type', 20);
            $table->string('name');
            $table->string('key_prefix', 20);
            $table->string('key_hash', 64);
            $table->jsonb('scopes');
            $table->unsignedInteger('rate_limit')->default(60);
            $table->jsonb('ip_restrictions')->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->foreignUuid('created_by')->constrained('users');
            $table->timestamps();
            $table->index(['school_id', 'revoked_at']);
        });
        Schema::create('administrator_webhooks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->nullable()->constrained('schools');
            $table->string('scope_type', 20);
            $table->string('name');
            $table->string('endpoint');
            $table->jsonb('events');
            $table->string('secret_hash', 64);
            $table->text('secret_encrypted');
            $table->boolean('enabled')->default(true);
            $table->unsignedSmallInteger('retry_limit')->default(3);
            $table->timestamp('rotated_at')->nullable();
            $table->foreignUuid('created_by')->constrained('users');
            $table->foreignUuid('updated_by')->constrained('users');
            $table->timestamps();
        });
        Schema::create('administrator_webhook_deliveries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('webhook_id')->constrained('administrator_webhooks');
            $table->string('event', 80);
            $table->string('status', 20);
            $table->unsignedSmallInteger('attempt_count')->default(0);
            $table->string('safe_failure_code', 60)->nullable();
            $table->timestamp('next_attempt_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'next_attempt_at']);
        });
        Schema::create('administrator_diagnostic_runs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('requested_by')->constrained('users');
            $table->string('scope_type', 20);
            $table->foreignUuid('school_id')->nullable()->constrained('schools');
            $table->jsonb('checks');
            $table->string('status', 20);
            $table->jsonb('safe_results')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });
        Schema::create('administrator_system_notices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('notice_type', 30);
            $table->string('audience', 30);
            $table->string('title');
            $table->text('message');
            $table->string('status', 20)->default('draft');
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->foreignUuid('created_by')->constrained('users');
            $table->foreignUuid('published_by')->nullable()->constrained('users');
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
        });
        Schema::create('administrator_platform_settings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('key', 100)->unique();
            $table->jsonb('value');
            $table->unsignedInteger('version')->default(1);
            $table->foreignUuid('updated_by')->constrained('users');
            $table->timestamps();
        });
        Schema::create('administrator_platform_setting_history', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('key', 100);
            $table->jsonb('old_value')->nullable();
            $table->jsonb('new_value');
            $table->unsignedInteger('version');
            $table->foreignUuid('actor_user_id')->constrained('users');
            $table->timestamp('created_at');
        });
    }

    public function down(): void {}
};
