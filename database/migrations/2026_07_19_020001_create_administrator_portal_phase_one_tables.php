<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('schools', function (Blueprint $table) {
            $table->string('short_name')->nullable();
            $table->string('motto')->nullable();
            $table->string('lifecycle_state', 30)->default('active')->index();
            $table->unsignedInteger('lifecycle_version')->default(1);
            $table->string('timezone', 60)->default('Africa/Nairobi');
            $table->string('locale', 10)->default('en');
            $table->string('academic_contact')->nullable();
            $table->string('finance_contact')->nullable();
            $table->string('communication_contact')->nullable();
            $table->timestamp('suspended_at')->nullable();
            $table->timestamp('locked_at')->nullable();
            $table->timestamp('archived_at')->nullable();
        });
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedInteger('auth_generation')->default(1);
            $table->timestamp('suspended_at')->nullable();
            $table->timestamp('force_password_reset_at')->nullable();
        });
        Schema::table('roles', function (Blueprint $table) {
            $table->uuid('school_id')->nullable()->index();
            $table->boolean('system_role')->default(true);
            $table->boolean('active')->default(true);
            $table->timestamp('updated_at')->nullable();
        });

        Schema::create('school_lifecycle_history', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools');
            $table->string('from_state', 30)->nullable();
            $table->string('to_state', 30);
            $table->text('reason')->nullable();
            $table->foreignUuid('actor_user_id')->constrained('users');
            $table->timestamp('created_at');
            $table->index(['school_id', 'created_at']);
        });
        Schema::create('school_subscription_history', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools');
            $table->uuid('subscription_id');
            $table->uuid('plan_id')->nullable();
            $table->string('status', 30);
            $table->jsonb('safe_snapshot');
            $table->foreignUuid('actor_user_id')->nullable()->constrained('users');
            $table->timestamp('created_at');
            $table->index(['school_id', 'created_at']);
        });
        Schema::create('administrator_preferences', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools');
            $table->foreignUuid('user_id')->constrained('users')->unique();
            $table->jsonb('dashboard_widgets')->nullable();
            $table->unsignedSmallInteger('default_page_size')->default(25);
            $table->string('timezone', 60)->default('Africa/Nairobi');
            $table->string('language', 10)->default('en');
            $table->jsonb('notification_preferences')->nullable();
            $table->string('digest_frequency', 20)->default('daily');
            $table->unsignedSmallInteger('default_audit_range_days')->default(30);
            $table->string('preferred_dashboard', 20)->default('school');
            $table->boolean('show_system_health')->default(true);
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
        });
        Schema::create('administrator_branding_assets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools');
            $table->string('asset_type', 40);
            $table->string('original_filename');
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('size');
            $table->string('source_hash', 64);
            $table->string('stored_hash', 64);
            $table->string('storage_id', 64);
            $table->string('status', 30)->default('pending_scan');
            $table->unsignedInteger('version')->default(1);
            $table->foreignUuid('uploaded_by')->constrained('users');
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
            $table->index(['school_id', 'asset_type', 'status']);
        });
        Schema::create('administrator_imports', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools');
            $table->foreignUuid('created_by')->constrained('users');
            $table->string('import_type', 40);
            $table->string('idempotency_key_hash', 64);
            $table->string('storage_id', 64);
            $table->string('source_hash', 64);
            $table->string('status', 30)->default('previewed');
            $table->jsonb('header_snapshot');
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('valid_rows')->default(0);
            $table->unsignedInteger('invalid_rows')->default(0);
            $table->unsignedInteger('processed_rows')->default(0);
            $table->timestamp('previewed_at');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();
            $table->unique(['school_id', 'created_by', 'idempotency_key_hash']);
            $table->index(['school_id', 'status', 'created_at']);
        });
        Schema::create('administrator_import_errors', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools');
            $table->foreignUuid('import_id')->constrained('administrator_imports');
            $table->unsignedInteger('row_number');
            $table->string('field')->nullable();
            $table->string('error_code', 60);
            $table->text('safe_message');
            $table->timestamp('created_at');
            $table->index(['import_id', 'row_number']);
        });
        Schema::create('administrator_alerts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->nullable()->constrained('schools');
            $table->string('alert_key', 180);
            $table->string('type', 60);
            $table->string('severity', 20);
            $table->string('title');
            $table->text('safe_message');
            $table->string('status', 20)->default('open');
            $table->timestamp('source_updated_at')->nullable();
            $table->timestamps();
            $table->unique(['school_id', 'alert_key']);
        });
        Schema::create('administrator_alert_states', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('alert_id')->constrained('administrator_alerts');
            $table->foreignUuid('user_id')->constrained('users');
            $table->string('state', 20);
            $table->timestamp('changed_at');
            $table->unique(['alert_id', 'user_id']);
        });
        Schema::create('administrator_reports', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->nullable()->constrained('schools');
            $table->foreignUuid('requested_by')->constrained('users');
            $table->string('report_type', 60);
            $table->string('scope', 20);
            $table->jsonb('safe_filters')->nullable();
            $table->string('status', 20)->default('queued');
            $table->string('storage_id', 64)->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->index(['requested_by', 'created_at']);
        });
        Schema::create('administrator_health_snapshots', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('component', 30);
            $table->string('status', 20);
            $table->jsonb('safe_metrics')->nullable();
            $table->timestamp('checked_at');
            $table->unique('component');
        });
    }

    public function down(): void {}
};
