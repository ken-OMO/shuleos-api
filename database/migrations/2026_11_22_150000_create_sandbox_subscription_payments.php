<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sandbox_subscription_payments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('package_id')->constrained('subscription_packages')->restrictOnDelete();
            $table->unsignedInteger('amount_kes');
            $table->unsignedInteger('duration_months');
            $table->string('status', 32);
            $table->string('checkout_id', 100)->nullable()->unique();
            $table->string('merchant_id', 100)->nullable();
            $table->foreignUuid('subscription_id')->nullable()->unique()->constrained('school_subscriptions')->restrictOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sandbox_subscription_payments');
    }
};
