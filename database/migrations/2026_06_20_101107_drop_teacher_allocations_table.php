<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run migrations.
     */
    public function up(): void
    {
        Schema::dropIfExists(

            'teacher_allocations'

        );
    }

    /**
     * Reverse migrations.
     */
    public function down(): void
    {
        //
    }
};
