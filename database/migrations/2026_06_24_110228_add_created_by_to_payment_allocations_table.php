<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_allocations', function (Blueprint $table) {

            $table->uuid('created_by')

                ->nullable()

                ->after('allocated_amount');

        });
    }

    public function down(): void
    {
        Schema::table('payment_allocations', function (Blueprint $table) {

            $table->dropColumn(

                'created_by'

            );

        });
    }
};
