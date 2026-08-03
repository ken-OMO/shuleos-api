<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('attendance_registers') && ! Schema::hasColumn('attendance_registers', 'sync_version')) {
            Schema::table('attendance_registers', fn (Blueprint $table) => $table->unsignedInteger('sync_version')->default(1));
        }
    }

    public function down(): void {}
};
