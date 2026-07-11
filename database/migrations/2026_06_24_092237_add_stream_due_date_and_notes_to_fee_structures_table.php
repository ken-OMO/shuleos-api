<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fee_structures', function (Blueprint $table) {

            $table->uuid('stream_id')

                ->nullable()

                ->after('grade_id');

            $table->date('due_date')

                ->nullable()

                ->after('amount');

            $table->text('notes')

                ->nullable()

                ->after('due_date');

        });
    }

    public function down(): void
    {
        Schema::table('fee_structures', function (Blueprint $table) {

            $table->dropColumn([

                'stream_id',

                'due_date',

                'notes',

            ]);

        });
    }
};
