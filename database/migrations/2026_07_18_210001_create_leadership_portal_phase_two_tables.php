<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leadership_dashboard_preferences', function (Blueprint $table) {
            $table->string('default_role_view', 30)->nullable();
            $table->uuid('preferred_grade_id')->nullable();
            $table->uuid('preferred_learning_area_id')->nullable();
            $table->string('timezone', 64)->default('Africa/Nairobi');
            $table->string('language', 12)->default('en');
            $table->jsonb('dashboard_widgets')->nullable();
            $table->jsonb('notification_preferences')->nullable();
            $table->string('digest_frequency', 20)->default('daily');
            $table->time('quiet_hours_start')->nullable();
            $table->time('quiet_hours_end')->nullable();
            $table->unsignedSmallInteger('default_date_range_days')->default(30);
            $table->jsonb('kpi_widget_order')->nullable();
        });

        Schema::create('leadership_portal_devices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools');
            $table->foreignUuid('user_id')->constrained('users');
            $table->string('device_identifier_hash', 64);
            $table->text('push_token_encrypted')->nullable();
            $table->string('platform', 20);
            $table->string('device_name')->nullable();
            $table->string('app_version', 40)->nullable();
            $table->boolean('push_enabled')->default(false);
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
            $table->unique(['school_id', 'user_id', 'device_identifier_hash'], 'leadership_device_owner_hash_unique');
            $table->index(['school_id', 'user_id', 'revoked_at']);
        });

        Schema::create('leadership_alert_states', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools');
            $table->foreignUuid('user_id')->constrained('users');
            $table->string('alert_key', 160);
            $table->string('state', 20);
            $table->timestamp('acted_at');
            $table->timestamps();
            $table->unique(['school_id', 'user_id', 'alert_key']);
        });

        Schema::create('leadership_portal_audit_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools');
            $table->foreignUuid('actor_user_id')->constrained('users');
            $table->string('action', 80);
            $table->string('entity_type', 80)->nullable();
            $table->uuid('entity_id')->nullable();
            $table->jsonb('safe_metadata')->nullable();
            $table->timestamp('created_at');
            $table->index(['school_id', 'created_at']);
            $table->index(['actor_user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leadership_portal_audit_logs');
        Schema::dropIfExists('leadership_alert_states');
        Schema::dropIfExists('leadership_portal_devices');

        Schema::table('leadership_dashboard_preferences', function (Blueprint $table) {
            $table->dropColumn([
                'default_role_view', 'preferred_grade_id', 'preferred_learning_area_id', 'timezone',
                'language', 'dashboard_widgets', 'notification_preferences', 'digest_frequency',
                'quiet_hours_start', 'quiet_hours_end', 'default_date_range_days', 'kpi_widget_order',
            ]);
        });
    }
};
