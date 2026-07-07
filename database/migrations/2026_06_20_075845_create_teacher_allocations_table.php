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
            'teacher_allocations',

            function (Blueprint $table) {

                $table->uuid('id')->primary();

                $table->uuid('school_id');

                $table->uuid('teacher_id');

                $table->uuid('learning_area_id');

                $table->uuid('grade_id');

                $table->uuid('stream_id');

                $table->boolean('active')

                    ->default(true);

                $table->timestamp('created_at')

                    ->useCurrent();

                /*
                |--------------------------------------
                | Foreign Keys
                |--------------------------------------
                */

                $table->foreign('school_id')

                    ->references('id')

                    ->on('schools');

                $table->foreign('teacher_id')

                    ->references('id')

                    ->on('teachers');

                $table->foreign('learning_area_id')

                    ->references('id')

                    ->on('learning_areas');

                $table->foreign('grade_id')

                    ->references('id')

                    ->on('grades');

                $table->foreign('stream_id')

                    ->references('id')

                    ->on('streams');

                /*
                |--------------------------------------
                | Prevent duplicates
                |--------------------------------------
                */

                $table->unique([

                    'teacher_id',

                    'learning_area_id',

                    'grade_id',

                    'stream_id',

                ], 'uq_teacher_allocation');

            }

        );
    }

    /**
     * Reverse migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists(

            'teacher_allocations'

        );
    }
};
