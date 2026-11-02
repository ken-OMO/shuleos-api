<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('learner_mode_of_study_history', function (Blueprint $table): void {
            // Historical dates were not captured; do not invent them.
            $table->date('effective_date')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('learner_mode_of_study_history', function (Blueprint $table): void {
            $table->dropColumn('effective_date');
        });
    }
};
