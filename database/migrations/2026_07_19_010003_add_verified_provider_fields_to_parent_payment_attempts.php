<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('parent_payment_attempts', function (Blueprint $table) {
            $table->string('provider_receipt')->nullable();
            $table->unsignedBigInteger('confirmed_amount_minor')->nullable();
            $table->string('confirmed_currency', 3)->nullable();
            $table->unique(['provider', 'provider_receipt']);
        });
    }

    public function down(): void {}
};
