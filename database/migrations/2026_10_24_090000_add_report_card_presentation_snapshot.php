<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('report_cards', function (Blueprint $table): void {
            $table->jsonb('presentation_snapshot')->nullable();
        });
    }

    public function down(): void
    {
        if (DB::table('report_cards')->whereNotNull('presentation_snapshot')->exists()) {
            throw new RuntimeException('Publication history must be preserved before removing presentation snapshots.');
        }

        Schema::table('report_cards', function (Blueprint $table): void {
            $table->dropColumn('presentation_snapshot');
        });
    }
};
