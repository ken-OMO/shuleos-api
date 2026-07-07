<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {

            $table->decimal('allocated_amount', 12, 2)

                ->default(0)

                ->after('amount');

            $table->string('payment_channel')

                ->nullable()

                ->after('allocated_amount');

        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {

            $table->dropColumn([

                'allocated_amount',

                'payment_channel'

            ]);

        });
    }
};
