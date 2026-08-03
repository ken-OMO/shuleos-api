<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('parent_portal_devices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools');
            $table->foreignUuid('user_id')->constrained('users');
            $table->string('device_identifier_hash', 64);
            $table->string('platform', 20);
            $table->string('app_version', 50)->nullable();
            $table->string('device_name', 120)->nullable();
            $table->text('push_token_encrypted')->nullable();
            $table->boolean('push_enabled')->default(false);
            $table->timestamp('last_seen_at');
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'device_identifier_hash']);
            $table->index(['school_id', 'user_id', 'revoked_at']);
        });

        Schema::create('parent_contact_change_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools');
            $table->foreignUuid('user_id')->constrained('users');
            $table->string('contact_type', 20);
            $table->text('requested_value_encrypted');
            $table->string('requested_value_hash', 64);
            $table->string('status', 30)->default('pending');
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();
            $table->index(['school_id', 'user_id', 'status']);
        });

        Schema::create('parent_portal_audit_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools');
            $table->foreignUuid('user_id')->constrained('users');
            $table->uuid('learner_id')->nullable()->index();
            $table->string('action', 80)->index();
            $table->string('entity_type', 80)->nullable();
            $table->uuid('entity_id')->nullable();
            $table->jsonb('safe_metadata')->nullable();
            $table->timestamp('created_at');
            $table->index(['school_id', 'user_id', 'created_at']);
        });
    }

    public function down(): void {}
};
