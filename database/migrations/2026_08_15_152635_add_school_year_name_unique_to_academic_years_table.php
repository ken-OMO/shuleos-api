<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('academic_years', function (Blueprint $table) {
            $table->unique(
                [
                    'school_id',
                    'year_name',
                ],
                'academic_years_school_year_name_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::table('academic_years', function (Blueprint $table) {
            $table->dropUnique(
                'academic_years_school_year_name_unique'
            );
        });
    }
};
