<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $table->unsignedInteger('version')->default(1);
        });
        Schema::table('announcement_reads', function (Blueprint $table) {
            $table->unsignedInteger('version')->default(1);
            $table->timestamp('updated_at')->nullable();
        });
        Schema::table('learner_offline_resources', function (Blueprint $table) {
            $table->unsignedInteger('version')->default(1);
        });
    }

    public function down(): void
    {
        Schema::table('learner_offline_resources', fn (Blueprint $table) => $table->dropColumn('version'));
        Schema::table('announcement_reads', fn (Blueprint $table) => $table->dropColumn(['version', 'updated_at']));
        Schema::table('notifications', fn (Blueprint $table) => $table->dropColumn('version'));
    }
};
