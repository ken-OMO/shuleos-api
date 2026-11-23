<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('registration_addon_prices', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('code', 30)->unique();
            $table->decimal('amount', 12, 2);
            $table->unsignedSmallInteger('duration_months');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('registration_addon_prices');
    }
};
