<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lesson_plans', function (Blueprint $table) {

            $table->unique(
                [
                    'teacher_assignment_id',
                    'scheme_lesson_id'
                ],
                'lesson_plans_assignment_lesson_unique'
            );

        });
    }

    public function down(): void
    {
        Schema::table('lesson_plans', function (Blueprint $table) {

            $table->dropUnique(
                'lesson_plans_assignment_lesson_unique'
            );

        });
    }
};
