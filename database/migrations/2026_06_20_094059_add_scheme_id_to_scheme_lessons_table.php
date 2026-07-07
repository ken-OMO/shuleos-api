<?php

use Illuminate\Database\Migrations\Migration;

use Illuminate\Database\Schema\Blueprint;

use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run migrations.
     */
    public function up(): void
    {
        Schema::table(

            'scheme_lessons',

            function (Blueprint $table) {

                $table->uuid('scheme_id')

                    ->after('id');

                $table->foreign('scheme_id')

                    ->references('id')

                    ->on('schemes_of_work')

                    ->cascadeOnDelete();

            }

        );
    }

    /**
     * Reverse migrations.
     */
    public function down(): void
    {
        Schema::table(

            'scheme_lessons',

            function (Blueprint $table) {

                $table->dropForeign([

                    'scheme_id'

                ]);

                $table->dropColumn(

                    'scheme_id'

                );

            }

        );
    }
};
