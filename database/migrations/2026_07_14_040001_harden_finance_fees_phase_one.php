<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fee_categories', function (Blueprint $table) {
            $table->timestamp('updated_at')->nullable();
            $table->boolean('is_deleted')->default(false);
            $table->timestamp('deleted_at')->nullable();
            $table->uuid('deleted_by')->nullable();
        });
        Schema::table('fee_structures', function (Blueprint $table) {
            $table->string('status')->default('draft');
            $table->unsignedInteger('revision')->default(1);
            $table->uuid('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->uuid('created_by')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->boolean('is_deleted')->default(false);
        });
        Schema::table('learner_fee_accounts', function (Blueprint $table) {
            $table->timestamp('updated_at')->nullable();
            $table->unique(['school_id', 'learner_id']);
            $table->unique(['school_id', 'account_number']);
        });
        Schema::table('fee_invoices', function (Blueprint $table) {
            $table->uuid('learner_fee_account_id')->nullable()->index();
            $table->uuid('posted_by')->nullable();
            $table->uuid('cancelled_by')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->timestamp('updated_at')->nullable();
        });
        Schema::table('learner_fee_ledger', function (Blueprint $table) {
            $table->uuid('learner_fee_account_id')->nullable()->index();
            $table->string('posting_action')->default('original');
            $table->uuid('reverses_ledger_id')->nullable();
            $table->unique(['school_id', 'reference_type', 'reference_id', 'posting_action'], 'fee_ledger_reference_action_unique');
        });
        Schema::table('payments', function (Blueprint $table) {
            $table->timestamp('confirmed_at')->nullable();
            $table->uuid('confirmed_by')->nullable();
            $table->timestamp('updated_at')->nullable();
        });
        Schema::table('payment_allocations', function (Blueprint $table) {
            $table->string('status')->default('active');
            $table->uuid('ledger_entry_id')->nullable();
            $table->timestamp('reversed_at')->nullable();
            $table->uuid('reversed_by')->nullable();
            $table->text('reversal_reason')->nullable();
        });
    }

    public function down(): void {}
};
