<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scheme_lessons', function (Blueprint $table) {

            $table->unique(
                ['scheme_id', 'lesson_number'],
                'scheme_lessons_scheme_lesson_unique'
            );

        });
    }

    public function down(): void
    {
        Schema::table('scheme_lessons', function (Blueprint $table) {

            $table->dropUnique(
                'scheme_lessons_scheme_lesson_unique'
            );

        });
    }
};
