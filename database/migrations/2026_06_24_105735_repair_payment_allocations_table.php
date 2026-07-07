<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_allocations', function (Blueprint $table) {

            $table->renameColumn(

                'invoice_item_id',

                'invoice_id'

            );

            $table->renameColumn(

                'amount',

                'allocated_amount'

            );

            $table->uuid('school_id')

                ->nullable()

                ->after('id');

        });
    }

    public function down(): void
    {
        Schema::table('payment_allocations', function (Blueprint $table) {

            $table->renameColumn(

                'invoice_id',

                'invoice_item_id'

            );

            $table->renameColumn(

                'allocated_amount',

                'amount'

            );

            $table->dropColumn(

                'school_id'

            );

        });
    }
};
