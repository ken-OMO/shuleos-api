<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('school_registration_requests', function (Blueprint $table): void {
            $table->string('mpesa_payer_phone', 12)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('school_registration_requests', function (Blueprint $table): void {
            $table->dropColumn('mpesa_payer_phone');
        });
    }
};
