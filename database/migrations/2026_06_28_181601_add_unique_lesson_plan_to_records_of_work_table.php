<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('records_of_work', function (Blueprint $table) {

            $table->unique(
                [
                    'lesson_plan_id'
                ],
                'records_of_work_lesson_plan_unique'
            );

        });
    }

    public function down(): void
    {
        Schema::table('records_of_work', function (Blueprint $table) {

            $table->dropUnique(
                'records_of_work_lesson_plan_unique'
            );

        });
    }
};
