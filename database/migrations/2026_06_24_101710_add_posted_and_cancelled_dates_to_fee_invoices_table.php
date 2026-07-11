<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fee_invoices', function (Blueprint $table) {

            $table->timestamp('posted_at')

                ->nullable()

                ->after('due_date');

            $table->timestamp('cancelled_at')

                ->nullable()

                ->after('posted_at');

        });
    }

    public function down(): void
    {
        Schema::table('fee_invoices', function (Blueprint $table) {

            $table->dropColumn([

                'posted_at',

                'cancelled_at',

            ]);

        });
    }
};
