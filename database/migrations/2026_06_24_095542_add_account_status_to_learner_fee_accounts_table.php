<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('learner_fee_accounts', function (Blueprint $table) {

            $table->string('account_status')

                ->default('active')

                ->after('last_payment_date');

        });
    }

    public function down(): void
    {
        Schema::table('learner_fee_accounts', function (Blueprint $table) {

            $table->dropColumn('account_status');

        });
    }
};
