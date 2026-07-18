<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('communication_deliveries', function (Blueprint $table) {
            $table->string('provider', 40)->nullable()->index();
            $table->string('provider_message_id')->nullable()->index();
            $table->string('provider_status', 40)->nullable();
            $table->string('failure_code', 80)->nullable();
            $table->text('destination_encrypted')->nullable();
            $table->string('destination_hash', 64)->nullable()->index();
            $table->unsignedInteger('cost_minor')->nullable();
            $table->unsignedInteger('credits_used')->nullable();
            $table->unsignedSmallInteger('segment_count')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('last_provider_event_at')->nullable();
        });

        Schema::table('communication_recipient_snapshots', function (Blueprint $table) {
            $table->boolean('email_suppressed')->default(false);
            $table->boolean('sms_eligible')->default(false);
            $table->string('phone_hash', 64)->nullable();
        });

        Schema::create('communication_delivery_status_history', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('school_id')->index();
            $table->uuid('delivery_id')->index();
            $table->string('from_status', 30)->nullable();
            $table->string('to_status', 30);
            $table->string('source', 30);
            $table->string('provider_event_id')->nullable();
            $table->string('safe_reason', 500)->nullable();
            $table->timestamp('created_at');
        });

        Schema::create('communication_provider_webhook_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('provider', 40);
            $table->string('provider_event_id');
            $table->string('event_type', 80);
            $table->string('payload_hash', 64);
            $table->string('provider_message_id')->nullable()->index();
            $table->uuid('school_id')->nullable()->index();
            $table->uuid('delivery_id')->nullable()->index();
            $table->string('processing_status', 30)->default('received');
            $table->jsonb('safe_metadata')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamp('created_at');
            $table->unique(['provider', 'provider_event_id']);
        });

        Schema::create('communication_contact_health', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('school_id')->index();
            $table->uuid('user_id')->index();
            $table->string('channel', 20);
            $table->string('destination_hash', 64);
            $table->string('status', 20)->default('healthy')->index();
            $table->string('reason', 255)->nullable();
            $table->unsignedSmallInteger('hard_bounce_count')->default(0);
            $table->unsignedSmallInteger('soft_bounce_count')->default(0);
            $table->unsignedSmallInteger('complaint_count')->default(0);
            $table->timestamp('last_success_at')->nullable();
            $table->timestamp('last_failure_at')->nullable();
            $table->timestamp('suppressed_at')->nullable();
            $table->timestamp('restored_at')->nullable();
            $table->uuid('restored_by')->nullable();
            $table->text('restoration_reason')->nullable();
            $table->timestamps();
            $table->unique(['school_id', 'user_id', 'channel', 'destination_hash'], 'communication_contact_health_unique');
        });

        Schema::create('school_sms_wallets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('school_id')->unique();
            $table->bigInteger('balance_credits')->default(0);
            $table->unsignedBigInteger('low_balance_threshold')->default(0);
            $table->string('status', 20)->default('active');
            $table->unsignedBigInteger('version')->default(0);
            $table->timestamps();
        });

        Schema::create('sms_credit_transactions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('school_id')->index();
            $table->uuid('wallet_id')->index();
            $table->string('transaction_type', 20);
            $table->bigInteger('credits_delta');
            $table->unsignedBigInteger('balance_after');
            $table->string('reference')->unique();
            $table->uuid('communication_id')->nullable()->index();
            $table->uuid('delivery_id')->nullable()->index();
            $table->uuid('actor_user_id')->nullable();
            $table->text('reason')->nullable();
            $table->timestamp('created_at');
        });

        Schema::create('sms_rate_cards', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('provider', 40);
            $table->string('country_code', 8)->default('KE');
            $table->string('route', 60)->default('transactional');
            $table->unsignedInteger('cost_minor_per_segment');
            $table->unsignedInteger('credits_per_segment');
            $table->unsignedInteger('version')->default(1);
            $table->timestamp('effective_from');
            $table->timestamp('effective_until')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('sms_usage_records', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('school_id')->index();
            $table->uuid('communication_id')->index();
            $table->uuid('delivery_id')->unique();
            $table->uuid('rate_card_id');
            $table->unsignedInteger('rate_card_version');
            $table->unsignedSmallInteger('segment_count');
            $table->unsignedInteger('credits_reserved');
            $table->unsignedInteger('credits_consumed')->default(0);
            $table->unsignedInteger('cost_minor');
            $table->string('status', 20)->default('reserved');
            $table->timestamp('reserved_at');
            $table->timestamp('consumed_at')->nullable();
            $table->timestamp('refunded_at')->nullable();
            $table->timestamps();
        });

        Schema::create('communication_preferences', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('school_id')->index();
            $table->uuid('user_id')->index();
            $table->boolean('email_enabled')->default(true);
            $table->boolean('sms_enabled')->default(false);
            $table->boolean('in_app_enabled')->default(true);
            $table->string('digest_frequency', 20)->default('immediate');
            $table->time('quiet_hours_start')->nullable();
            $table->time('quiet_hours_end')->nullable();
            $table->string('timezone', 60)->default('Africa/Nairobi');
            $table->string('language', 10)->default('en');
            $table->boolean('emergency_override')->default(true);
            $table->boolean('marketing_opt_out')->default(false);
            $table->timestamps();
            $table->unique(['school_id', 'user_id']);
        });

        Schema::create('communication_branding', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('school_id')->unique();
            $table->string('sender_display_name')->nullable();
            $table->string('reply_to_email')->nullable();
            $table->string('logo_reference')->nullable();
            $table->text('footer_text')->nullable();
            $table->string('address')->nullable();
            $table->string('phone')->nullable();
            $table->string('website')->nullable();
            $table->string('primary_color', 7)->nullable();
            $table->string('secondary_color', 7)->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestamps();
        });

        Schema::create('recurring_communication_schedules', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('school_id')->index();
            $table->uuid('communication_id')->index();
            $table->uuid('created_by');
            $table->string('frequency', 20);
            $table->jsonb('selected_weekdays')->nullable();
            $table->unsignedInteger('maximum_occurrences')->nullable();
            $table->unsignedInteger('occurrences_dispatched')->default(0);
            $table->timestamp('starts_at');
            $table->timestamp('ends_at')->nullable();
            $table->timestamp('next_run_at')->index();
            $table->string('timezone', 60)->default('Africa/Nairobi');
            $table->string('missed_run_policy', 20)->default('skip');
            $table->string('status', 20)->default('active');
            $table->timestamps();
        });

        Schema::create('recurring_communication_runs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('school_id')->index();
            $table->uuid('schedule_id')->index();
            $table->uuid('communication_id')->nullable()->index();
            $table->timestamp('scheduled_for');
            $table->string('status', 20)->default('pending');
            $table->timestamp('dispatched_at')->nullable();
            $table->text('safe_failure_reason')->nullable();
            $table->timestamp('created_at');
            $table->unique(['schedule_id', 'scheduled_for']);
        });

        Schema::create('communication_digest_runs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('school_id')->index();
            $table->uuid('user_id')->index();
            $table->string('digest_type', 40);
            $table->date('digest_date');
            $table->string('content_hash', 64);
            $table->uuid('communication_id')->nullable();
            $table->string('status', 20)->default('generated');
            $table->timestamp('created_at');
            $table->unique(['school_id', 'user_id', 'digest_type', 'digest_date']);
        });

        Schema::create('communication_emergency_confirmations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('school_id')->index();
            $table->uuid('user_id')->index();
            $table->string('token_hash', 64)->unique();
            $table->string('payload_hash', 64);
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->timestamp('created_at');
        });
    }

    public function down(): void {}
};
