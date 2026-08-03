<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('communications')) {
            Schema::create('communications', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('school_id')->index();
                $table->uuid('sender_user_id')->index();
                $table->string('communication_type', 50)->index();
                $table->string('category', 50)->index();
                $table->string('priority', 20)->default('normal')->index();
                $table->string('subject', 255);
                $table->text('body');
                $table->string('status', 30)->default('draft')->index();
                $table->jsonb('channels')->nullable();
                $table->boolean('requires_approval')->default(false);
                $table->string('risk_level', 20)->default('low');
                $table->uuid('approved_by')->nullable();
                $table->timestamp('approved_at')->nullable();
                $table->uuid('rejected_by')->nullable();
                $table->timestamp('rejected_at')->nullable();
                $table->text('rejection_reason')->nullable();
                $table->timestamp('scheduled_for')->nullable()->index();
                $table->timestamp('expires_at')->nullable();
                $table->timestamp('sent_at')->nullable();
                $table->timestamp('cancelled_at')->nullable();
                $table->text('cancellation_reason')->nullable();
                $table->jsonb('metadata')->nullable();
                $table->unsignedInteger('recipient_count')->default(0);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('communication_targets')) {
            Schema::create('communication_targets', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('school_id')->index();
                $table->uuid('communication_id')->index();
                $table->string('target_type', 60)->index();
                $table->jsonb('options')->nullable();
                $table->timestamp('created_at')->nullable();
            });
        }

        if (! Schema::hasTable('communication_recipient_snapshots')) {
            Schema::create('communication_recipient_snapshots', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('school_id')->index();
                $table->uuid('communication_id')->index();
                $table->uuid('user_id')->index();
                $table->string('audience_type', 30)->nullable();
                $table->jsonb('context')->nullable();
                $table->string('email')->nullable();
                $table->boolean('email_valid')->default(false);
                $table->timestamp('resolved_at');
                $table->unique(['communication_id', 'user_id']);
            });
        }

        if (! Schema::hasTable('communication_deliveries')) {
            Schema::create('communication_deliveries', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('school_id')->index();
                $table->uuid('communication_id')->index();
                $table->uuid('recipient_user_id')->index();
                $table->string('channel', 20)->index();
                $table->string('status', 30)->default('pending')->index();
                $table->string('delivery_key')->unique();
                $table->unsignedSmallInteger('attempt_count')->default(0);
                $table->text('failure_reason')->nullable();
                $table->timestamp('queued_at')->nullable();
                $table->timestamp('sent_at')->nullable();
                $table->timestamp('delivered_at')->nullable();
                $table->timestamps();
                $table->unique(['communication_id', 'recipient_user_id', 'channel']);
            });
        }

        if (! Schema::hasTable('communication_templates')) {
            Schema::create('communication_templates', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('school_id')->nullable()->index();
                $table->string('name');
                $table->string('category', 60)->index();
                $table->string('subject');
                $table->text('body');
                $table->unsignedInteger('version')->default(1);
                $table->boolean('is_system')->default(false);
                $table->boolean('active')->default(true);
                $table->uuid('created_by')->nullable();
                $table->timestamp('archived_at')->nullable();
                $table->timestamps();
            });
        } else {
            Schema::table('communication_templates', function (Blueprint $table) {
                if (! Schema::hasColumn('communication_templates', 'school_id')) {
                    $table->uuid('school_id')->nullable()->index();
                }
                if (! Schema::hasColumn('communication_templates', 'category')) {
                    $table->string('category', 60)->default('general')->index();
                }
                if (! Schema::hasColumn('communication_templates', 'subject')) {
                    $table->string('subject')->default('Notification');
                }
                if (! Schema::hasColumn('communication_templates', 'body')) {
                    $table->text('body')->nullable();
                }
                if (! Schema::hasColumn('communication_templates', 'version')) {
                    $table->unsignedInteger('version')->default(1);
                }
                if (! Schema::hasColumn('communication_templates', 'is_system')) {
                    $table->boolean('is_system')->default(false);
                }
                if (! Schema::hasColumn('communication_templates', 'active')) {
                    $table->boolean('active')->default(true);
                }
                if (! Schema::hasColumn('communication_templates', 'created_by')) {
                    $table->uuid('created_by')->nullable();
                }
                if (! Schema::hasColumn('communication_templates', 'archived_at')) {
                    $table->timestamp('archived_at')->nullable();
                }
                if (! Schema::hasColumn('communication_templates', 'created_at')) {
                    $table->timestamp('created_at')->nullable();
                }
                if (! Schema::hasColumn('communication_templates', 'updated_at')) {
                    $table->timestamp('updated_at')->nullable();
                }
            });
        }

        if (! Schema::hasTable('communication_policies')) {
            Schema::create('communication_policies', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('school_id')->index();
                $table->string('category', 60);
                $table->jsonb('enabled_channels');
                $table->string('minimum_priority', 20)->default('low');
                $table->boolean('requires_approval')->default(false);
                $table->unsignedInteger('approval_recipient_threshold')->default(100);
                $table->unsignedInteger('critical_recipient_threshold')->default(1000);
                $table->jsonb('allowed_sender_roles')->nullable();
                $table->boolean('allow_scheduling')->default(true);
                $table->unsignedInteger('default_expiry_days')->nullable();
                $table->boolean('sms_enabled')->default(false);
                $table->uuid('updated_by')->nullable();
                $table->timestamps();
                $table->unique(['school_id', 'category']);
            });
        }

        if (! Schema::hasTable('communication_audit_logs')) {
            Schema::create('communication_audit_logs', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('school_id')->index();
                $table->uuid('communication_id')->nullable()->index();
                $table->uuid('actor_user_id')->nullable()->index();
                $table->string('action', 80)->index();
                $table->string('entity_type', 80);
                $table->uuid('entity_id')->nullable();
                $table->jsonb('metadata')->nullable();
                $table->timestamp('created_at');
            });
        }

        if (! Schema::hasTable('announcement_reads')) {
            Schema::create('announcement_reads', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('school_id')->index();
                $table->uuid('communication_id')->index();
                $table->uuid('user_id')->index();
                $table->timestamp('read_at');
                $table->unique(['communication_id', 'user_id']);
            });
        }

        Schema::table('notifications', function (Blueprint $table) {
            if (! Schema::hasColumn('notifications', 'communication_id')) {
                $table->uuid('communication_id')->nullable()->index();
            }
            if (! Schema::hasColumn('notifications', 'delivery_id')) {
                $table->uuid('delivery_id')->nullable()->index();
            }
            if (! Schema::hasColumn('notifications', 'state')) {
                $table->string('state', 20)->default('unread')->index();
            }
            if (! Schema::hasColumn('notifications', 'read_at')) {
                $table->timestamp('read_at')->nullable();
            }
            if (! Schema::hasColumn('notifications', 'archived_at')) {
                $table->timestamp('archived_at')->nullable();
            }
            if (! Schema::hasColumn('notifications', 'dismissed_at')) {
                $table->timestamp('dismissed_at')->nullable();
            }
            if (! Schema::hasColumn('notifications', 'updated_at')) {
                $table->timestamp('updated_at')->nullable();
            }
        });

        DB::table('notifications')
            ->where('is_read', true)
            ->update([
                'state' => 'read',
                'read_at' => DB::raw('COALESCE(read_at, created_at)'),
                'updated_at' => DB::raw('COALESCE(updated_at, created_at)'),
            ]);
    }

    public function down(): void {}
};
