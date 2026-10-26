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
        Schema::table('learners', function (Blueprint $table): void {
            $table->string('kemis_no', 50)->nullable();
        });
    }

    public function down(): void
    {
        if (DB::table('learners')->whereNotNull('kemis_no')->exists()) {
            throw new RuntimeException(
                'Cannot remove KEMIS numbers while learner records contain them.'
            );
        }

        Schema::table('learners', function (Blueprint $table): void {
            $table->dropColumn('kemis_no');
        });
    }
};
