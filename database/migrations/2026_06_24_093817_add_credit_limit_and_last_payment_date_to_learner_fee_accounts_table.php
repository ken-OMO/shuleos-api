<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('learner_fee_accounts', function (Blueprint $table) {

            $table->decimal('credit_limit', 12, 2)

                ->default(0)

                ->after('current_balance');

            $table->date('last_payment_date')

                ->nullable()

                ->after('credit_limit');

        });
    }

    public function down(): void
    {
        Schema::table('learner_fee_accounts', function (Blueprint $table) {

            $table->dropColumn([

                'credit_limit',

                'last_payment_date',

            ]);

        });
    }
};
