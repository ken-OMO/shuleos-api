<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fee_invoices', function (Blueprint $table) {

            $table->uuid('fee_structure_id')

                ->nullable()

                ->after('stream_id');

            $table->uuid('generated_by')

                ->nullable()

                ->after('due_date');

            $table->text('notes')

                ->nullable()

                ->after('generated_by');

        });
    }

    public function down(): void
    {
        Schema::table('fee_invoices', function (Blueprint $table) {

            $table->dropColumn([

                'fee_structure_id',

                'generated_by',

                'notes',

            ]);

        });
    }
};
