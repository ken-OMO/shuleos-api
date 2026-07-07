<?php

use Illuminate\Database\Migrations\Migration;

use Illuminate\Database\Schema\Blueprint;

use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(

            'curriculum_coverage',

            function (Blueprint $table) {

                $table->uuid('id')

                    ->primary();

                $table->uuid('school_id');

                $table->uuid('teacher_assignment_id');

                $table->uuid('scheme_id');

                $table->uuid('scheme_lesson_id');

                $table->uuid('record_of_work_id');

                $table->date('date_completed');

                $table->string('strand');

                $table->string('sub_strand');

                $table->integer('week_number');

                $table->boolean('completed')

                    ->default(true);

                $table->timestamp('created_at')

                    ->useCurrent();

            }

        );
    }

    public function down(): void
    {
        Schema::dropIfExists(

            'curriculum_coverage'

        );
    }
};
