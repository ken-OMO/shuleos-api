<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fee_invoices', function (Blueprint $table) {

            $table->uuid('grade_id')

                ->nullable()

                ->after('learner_id');

            $table->uuid('stream_id')

                ->nullable()

                ->after('grade_id');

        });
    }

    public function down(): void
    {
        Schema::table('fee_invoices', function (Blueprint $table) {

            $table->dropColumn([

                'grade_id',

                'stream_id',

            ]);

        });
    }
};
