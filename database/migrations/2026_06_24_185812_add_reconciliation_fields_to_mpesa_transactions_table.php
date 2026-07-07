<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mpesa_transactions', function (Blueprint $table) {

            $table->uuid('processed_by')

                ->nullable()

                ->after('callback_payload');

            $table->boolean('is_reconciled')

                ->default(false)

                ->after('processed_by');

            $table->timestamp('reconciled_at')

                ->nullable()

                ->after('is_reconciled');

        });
    }

    public function down(): void
    {
        Schema::table('mpesa_transactions', function (Blueprint $table) {

            $table->dropColumn([

                'processed_by',

                'is_reconciled',

                'reconciled_at'

            ]);

        });
    }
};
