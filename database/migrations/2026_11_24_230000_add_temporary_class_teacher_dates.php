<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('class_teachers', function (Blueprint $table) {
            $table->date('temporary_until')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('class_teachers', function (Blueprint $table) {
            $table->dropColumn('temporary_until');
        });
    }
};
