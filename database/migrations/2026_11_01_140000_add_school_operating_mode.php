<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Existing schools require an explicit classification.
        Schema::table('schools', function (Blueprint $table): void {
            $table->string('operating_mode', 20)->nullable();
        });

        DB::statement(
            "ALTER TABLE schools
             ADD CONSTRAINT schools_operating_mode_check
             CHECK (operating_mode IS NULL OR operating_mode IN (
                 'day', 'boarding', 'day_and_boarding'
             ))"
        );
    }

    public function down(): void
    {
        DB::statement(
            'ALTER TABLE schools DROP CONSTRAINT schools_operating_mode_check'
        );

        Schema::table('schools', function (Blueprint $table): void {
            $table->dropColumn('operating_mode');
        });
    }
};
