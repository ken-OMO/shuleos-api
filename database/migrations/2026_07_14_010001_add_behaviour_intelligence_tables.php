<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('discipline_categories', function (Blueprint $t) {
            foreach (['category_type', 'default_severity'] as $c) {
                if (! Schema::hasColumn('discipline_categories', $c)) {
                    $t->string($c)->nullable();
                }
            }foreach (['requires_parent_notification', 'requires_counselling_referral', 'requires_leadership_review'] as $c) {
                if (! Schema::hasColumn('discipline_categories', $c)) {
                    $t->boolean($c)->default(false);
                }
            }if (! Schema::hasColumn('discipline_categories', 'is_deleted')) {
                $t->boolean('is_deleted')->default(false);
            }if (! Schema::hasColumn('discipline_categories', 'deleted_at')) {
                $t->timestamp('deleted_at')->nullable();
            }if (! Schema::hasColumn('discipline_categories', 'deleted_by')) {
                $t->uuid('deleted_by')->nullable();
            }
        });
        Schema::table('discipline_cases', function (Blueprint $t) {
            foreach (['severity', 'priority'] as $c) {
                if (! Schema::hasColumn('discipline_cases', $c)) {
                    $t->string($c)->nullable();
                }
            }foreach (['assigned_to', 'reviewed_by', 'resolved_by', 'deleted_by'] as $c) {
                if (! Schema::hasColumn('discipline_cases', $c)) {
                    $t->uuid($c)->nullable();
                }
            }foreach (['reviewed_at', 'resolved_at', 'parent_notified_at', 'deleted_at'] as $c) {
                if (! Schema::hasColumn('discipline_cases', $c)) {
                    $t->timestamp($c)->nullable();
                }
            }foreach (['closure_notes'] as $c) {
                if (! Schema::hasColumn('discipline_cases', $c)) {
                    $t->text($c)->nullable();
                }
            }foreach (['parent_notification_required', 'confidential', 'safeguarding', 'is_deleted'] as $c) {
                if (! Schema::hasColumn('discipline_cases', $c)) {
                    $t->boolean($c)->default(false);
                }
            }if (! Schema::hasColumn('discipline_cases', 'updated_at')) {
                $t->timestamp('updated_at')->nullable();
            }
        });
        Schema::table('discipline_actions', function (Blueprint $t) {
            if (! Schema::hasColumn('discipline_actions', 'school_id')) {
                $t->uuid('school_id')->nullable();
            }
            foreach (['learner_id', 'assigned_to', 'updated_by', 'deleted_by'] as $c) {
                if (! Schema::hasColumn('discipline_actions', $c)) {
                    $t->uuid($c)->nullable();
                }
            }foreach (['start_at', 'due_at', 'completed_at', 'follow_up_at', 'updated_at', 'deleted_at'] as $c) {
                if (! Schema::hasColumn('discipline_actions', $c)) {
                    $t->timestamp($c)->nullable();
                }
            }if (! Schema::hasColumn('discipline_actions', 'status')) {
                $t->string('status')->default('planned');
            }if (! Schema::hasColumn('discipline_actions', 'follow_up_required')) {
                $t->boolean('follow_up_required')->default(false);
            }if (! Schema::hasColumn('discipline_actions', 'follow_up_notes')) {
                $t->text('follow_up_notes')->nullable();
            }if (! Schema::hasColumn('discipline_actions', 'is_deleted')) {
                $t->boolean('is_deleted')->default(false);
            }
        });
        Schema::create('behaviour_recognitions', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('school_id')->constrained('schools');
            $t->foreignUuid('learner_id')->constrained('learners');
            $t->uuid('category_id')->nullable();
            $t->string('recognition_type');
            $t->string('title');
            $t->text('description')->nullable();
            $t->integer('points')->nullable();
            $t->foreignUuid('awarded_by')->constrained('users');
            $t->timestamp('awarded_at');
            $t->boolean('visible_to_learner')->default(true);
            $t->boolean('visible_to_parent')->default(true);
            $t->foreignUuid('approved_by')->nullable()->constrained('users');
            $t->timestamp('approved_at')->nullable();
            $t->string('status')->default('draft');
            $t->timestamps();
            $t->boolean('is_deleted')->default(false);
            $t->timestamp('deleted_at')->nullable();
            $t->uuid('deleted_by')->nullable();
        });
        Schema::create('behaviour_counselling_referrals', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('school_id')->constrained('schools');
            $t->foreignUuid('learner_id')->constrained('learners');
            $t->uuid('discipline_case_id')->nullable();
            $t->uuid('attendance_alert_id')->nullable();
            $t->foreignUuid('referred_by')->constrained('users');
            $t->uuid('assigned_to')->nullable();
            $t->text('referral_reason');
            $t->string('priority');
            $t->string('status')->default('referred');
            $t->boolean('confidential')->default(true);
            $t->timestamp('referred_at');
            $t->timestamp('accepted_at')->nullable();
            $t->timestamp('completed_at')->nullable();
            $t->text('outcome_summary')->nullable();
            $t->timestamps();
        });
        Schema::create('attendance_risk_flags', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('school_id')->constrained('schools');
            $t->foreignUuid('learner_id')->constrained('learners');
            $t->string('flag_type');
            $t->string('severity');
            $t->date('period_start');
            $t->date('period_end');
            $t->decimal('metric_value', 10, 2)->nullable();
            $t->decimal('threshold_value', 10, 2)->nullable();
            $t->string('status')->default('open');
            $t->timestamp('generated_at');
            $t->uuid('acknowledged_by')->nullable();
            $t->timestamp('acknowledged_at')->nullable();
            $t->uuid('resolved_by')->nullable();
            $t->timestamp('resolved_at')->nullable();
            $t->text('resolution_notes')->nullable();
            $t->jsonb('metadata')->nullable();
            $t->unique(['school_id', 'learner_id', 'flag_type', 'period_start', 'period_end']);
        });
        Schema::create('behaviour_audit_logs', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('school_id')->constrained('schools');
            $t->uuid('learner_id')->nullable();
            $t->uuid('discipline_case_id')->nullable();
            $t->uuid('action_id')->nullable();
            $t->uuid('recognition_id')->nullable();
            $t->uuid('referral_id')->nullable();
            $t->foreignUuid('actor_user_id')->constrained('users');
            $t->string('action');
            $t->jsonb('previous_values')->nullable();
            $t->jsonb('new_values')->nullable();
            $t->text('reason')->nullable();
            $t->timestamp('created_at');
        });
    }

    public function down(): void
    {
        foreach (['behaviour_audit_logs', 'attendance_risk_flags', 'behaviour_counselling_referrals', 'behaviour_recognitions'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
