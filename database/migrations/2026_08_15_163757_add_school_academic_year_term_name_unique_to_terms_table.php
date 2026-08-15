<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('terms', function (Blueprint $table) {
            $table->unique(
                [
                    'school_id',
                    'academic_year_id',
                    'term_name',
                ],
                'terms_school_year_term_name_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::table('terms', function (Blueprint $table) {
            $table->dropUnique(
                'terms_school_year_term_name_unique'
            );
        });
    }
};
