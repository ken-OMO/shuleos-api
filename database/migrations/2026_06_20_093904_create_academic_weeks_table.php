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
        Schema::create(

            'academic_weeks',

            function (Blueprint $table) {

                $table->uuid('id')

                    ->primary();

                $table->uuid('school_id');

                $table->uuid('academic_year_id');

                $table->uuid('term_id');

                $table->integer('week_number');

                $table->date('start_date');

                $table->date('end_date');

                $table->boolean('active')

                    ->default(true);

                $table->timestamp('created_at')

                    ->useCurrent();

                $table->foreign('school_id')

                    ->references('id')

                    ->on('schools')

                    ->cascadeOnDelete();

                $table->foreign('academic_year_id')

                    ->references('id')

                    ->on('academic_years')

                    ->cascadeOnDelete();

                $table->foreign('term_id')

                    ->references('id')

                    ->on('terms')

                    ->cascadeOnDelete();

                $table->unique([

                    'term_id',

                    'week_number',

                ]);

            }

        );
    }

    /**
     * Reverse migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists(

            'academic_weeks'

        );
    }
};
