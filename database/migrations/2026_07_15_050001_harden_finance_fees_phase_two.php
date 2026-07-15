<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fee_discounts', function (Blueprint $table) {
            $table->text('description')->nullable();
            $table->decimal('maximum_discount', 12, 2)->nullable();
            $table->uuid('grade_id')->nullable()->index();
            $table->uuid('stream_id')->nullable()->index();
            $table->uuid('academic_year_id')->nullable()->index();
            $table->uuid('term_id')->nullable()->index();
            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();
            $table->string('status')->default('draft')->index();
            $table->unsignedInteger('revision')->default(1);
            $table->uuid('created_by')->nullable();
            $table->uuid('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->boolean('is_deleted')->default(false);
            $table->timestamp('deleted_at')->nullable();
        });

        Schema::create('fee_discount_categories', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('school_id')->index();
            $table->uuid('discount_id')->index();
            $table->uuid('fee_category_id')->index();
            $table->timestamp('created_at')->nullable();
            $table->unique(['discount_id', 'fee_category_id']);
        });

        Schema::table('learner_discounts', function (Blueprint $table) {
            $table->uuid('school_id')->nullable()->index();
            $table->uuid('learner_fee_account_id')->nullable()->index();
            $table->uuid('fee_category_id')->nullable()->index();
            $table->string('status')->default('pending')->index();
            $table->decimal('assigned_value', 12, 2)->nullable();
            $table->date('starts_at')->nullable();
            $table->date('ends_at')->nullable();
            $table->text('override_reason')->nullable();
            $table->text('private_notes')->nullable();
            $table->uuid('assigned_by')->nullable();
            $table->uuid('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->uuid('cancelled_by')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->boolean('is_deleted')->default(false);
        });

        Schema::create('fee_discount_applications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('school_id')->index();
            $table->uuid('invoice_id')->index();
            $table->uuid('learner_discount_id')->index();
            $table->uuid('discount_id')->index();
            $table->decimal('eligible_amount', 12, 2);
            $table->decimal('discount_amount', 12, 2);
            $table->string('status')->default('active');
            $table->uuid('ledger_entry_id')->nullable();
            $table->uuid('applied_by');
            $table->timestamp('applied_at');
            $table->uuid('reversed_by')->nullable();
            $table->timestamp('reversed_at')->nullable();
            $table->text('reversal_reason')->nullable();
            $table->timestamps();
            $table->unique(['invoice_id', 'learner_discount_id']);
        });

        Schema::table('payment_plans', function (Blueprint $table) {
            $table->uuid('learner_id')->nullable()->index();
            $table->uuid('learner_fee_account_id')->nullable()->index();
            $table->uuid('academic_year_id')->nullable()->index();
            $table->uuid('term_id')->nullable()->index();
            $table->decimal('total_planned_amount', 12, 2)->nullable();
            $table->string('status')->default('draft')->index();
            $table->uuid('created_by')->nullable();
            $table->uuid('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->uuid('cancelled_by')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->boolean('is_deleted')->default(false);
        });

        Schema::table('payment_plan_installments', function (Blueprint $table) {
            $table->uuid('school_id')->nullable()->index();
            $table->decimal('scheduled_amount', 12, 2)->nullable();
            $table->decimal('paid_amount', 12, 2)->default(0);
            $table->decimal('outstanding_amount', 12, 2)->nullable();
            $table->date('due_date')->nullable();
            $table->string('status')->default('pending')->index();
            $table->timestamp('updated_at')->nullable();
            $table->unique(['payment_plan_id', 'installment_order']);
        });

        Schema::create('payment_plan_invoices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('school_id')->index();
            $table->uuid('payment_plan_id')->index();
            $table->uuid('invoice_id')->index();
            $table->timestamp('created_at')->nullable();
            $table->unique(['payment_plan_id', 'invoice_id']);
        });

        Schema::table('fee_refunds', function (Blueprint $table) {
            $table->uuid('learner_fee_account_id')->nullable()->index();
            $table->string('status')->default('requested')->index();
            $table->uuid('requested_by')->nullable();
            $table->timestamp('requested_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->uuid('processed_by')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->uuid('rejected_by')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->text('decision_reason')->nullable();
            $table->uuid('cancelled_by')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->uuid('ledger_entry_id')->nullable();
            $table->timestamp('updated_at')->nullable();
        });
        Schema::table('payments', function (Blueprint $table) {
            $table->decimal('refunded_amount', 12, 2)->default(0);
        });
        Schema::table('payment_allocations', function (Blueprint $table) {
            $table->decimal('refunded_amount', 12, 2)->default(0);
        });

        Schema::table('finance_adjustments', function (Blueprint $table) {
            $table->uuid('learner_fee_account_id')->nullable()->index();
            $table->uuid('academic_year_id')->nullable()->index();
            $table->uuid('term_id')->nullable()->index();
            $table->string('direction')->nullable();
            $table->string('reference_type')->nullable();
            $table->uuid('reference_id')->nullable();
            $table->uuid('submitted_by')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->uuid('posted_by')->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->uuid('rejected_by')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->text('decision_reason')->nullable();
            $table->uuid('ledger_entry_id')->nullable();
            $table->uuid('reversal_ledger_entry_id')->nullable();
            $table->uuid('reversed_by')->nullable();
            $table->timestamp('reversed_at')->nullable();
            $table->text('reversal_reason')->nullable();
            $table->timestamp('updated_at')->nullable();
        });

        Schema::table('fee_arrears', function (Blueprint $table) {
            $table->uuid('learner_fee_account_id')->nullable()->index();
            $table->string('status')->default('outstanding')->index();
            $table->decimal('carried_forward_amount', 12, 2)->default(0);
            $table->uuid('carry_forward_ledger_id')->nullable();
            $table->uuid('source_resolution_ledger_id')->nullable();
            $table->timestamp('calculated_at')->nullable();
            $table->uuid('calculated_by')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->uuid('resolved_by')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->unique(['school_id', 'learner_id', 'academic_year_id', 'term_id']);
        });

        Schema::create('finance_clearances', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('school_id')->index();
            $table->uuid('learner_id')->index();
            $table->uuid('learner_fee_account_id')->index();
            $table->string('status')->default('not_cleared')->index();
            $table->decimal('balance_at_decision', 12, 2);
            $table->decimal('threshold', 12, 2)->default(0);
            $table->boolean('is_override')->default(false);
            $table->text('override_reason')->nullable();
            $table->uuid('approved_by')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->uuid('revoked_by')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->text('revocation_reason')->nullable();
            $table->timestamps();
            $table->unique(['school_id', 'learner_id']);
        });

        Schema::create('finance_clearance_certificates', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('school_id')->index();
            $table->uuid('learner_id')->index();
            $table->uuid('clearance_id')->index();
            $table->string('certificate_number')->unique();
            $table->timestamp('issued_at');
            $table->timestamp('expires_at')->nullable();
            $table->uuid('issued_by')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->uuid('revoked_by')->nullable();
            $table->text('revocation_reason')->nullable();
            $table->timestamps();
        });

        Schema::table('finance_settings', function (Blueprint $table) {
            $table->decimal('clearance_threshold', 12, 2)->default(0);
            $table->unsignedInteger('reminder_due_soon_days')->default(7);
            $table->boolean('finance_reminders_enabled')->default(true);
        });

        Schema::table('notifications', function (Blueprint $table) {
            $table->string('notification_key')->nullable();
            $table->string('notification_type')->nullable()->index();
            $table->uuid('learner_id')->nullable()->index();
            $table->string('action_url')->nullable();
            $table->unique(['school_id', 'user_id', 'notification_key']);
        });
    }

    public function down(): void {}
};
