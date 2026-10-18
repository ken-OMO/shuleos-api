<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('report_cards', function (Blueprint $table) {
            $table->boolean('has_absence')->default(false);
        });

        Schema::table('report_card_learning_areas', function (Blueprint $table) {
            $table->boolean('is_absent')->default(false);
        });
    }

    public function down(): void
    {
        // Include deleted cards and details: their snapshots remain historical data.
        if (DB::table('report_cards')->where('has_absence', true)->exists()
            || DB::table('report_card_learning_areas')->where('is_absent', true)->exists()) {
            throw new RuntimeException(
                'Cannot remove report-card absence snapshots while recorded absence remains.'
            );
        }

        Schema::table('report_card_learning_areas', function (Blueprint $table) {
            $table->dropColumn('is_absent');
        });

        Schema::table('report_cards', function (Blueprint $table) {
            $table->dropColumn('has_absence');
        });
    }
};
