<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('finance_settings', function (Blueprint $table) {

            $table->string('currency')

                ->default('KES')

                ->after('school_id');

        });
    }

    public function down(): void
    {
        Schema::table('finance_settings', function (Blueprint $table) {

            $table->dropColumn('currency');

        });
    }
};
