<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scheme_lessons', function (Blueprint $table) {
            $table->dropForeign('fk_sl_week');

            $table->foreign('week_id', 'scheme_lessons_week_id_foreign')
                ->references('id')
                ->on('academic_weeks');
        });
    }

    public function down(): void
    {
        Schema::table('scheme_lessons', function (Blueprint $table) {
            $table->dropForeign('scheme_lessons_week_id_foreign');

            $table->foreign('week_id', 'fk_sl_week')
                ->references('id')
                ->on('scheme_weeks');
        });
    }
};
