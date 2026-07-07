<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_plans', function (Blueprint $table) {

            $table->integer('number_of_installments')

                ->default(1)

                ->after('description');

        });
    }

    public function down(): void
    {
        Schema::table('payment_plans', function (Blueprint $table) {

            $table->dropColumn('number_of_installments');

        });
    }
};
