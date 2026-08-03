<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('parent_offline_drafts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools');
            $table->foreignUuid('user_id')->constrained('users');
            $table->string('entity_type', 50);
            $table->uuid('entity_id');
            $table->jsonb('safe_data');
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->unique(['user_id', 'entity_type', 'entity_id']);
            $table->index(['school_id', 'user_id', 'updated_at']);
        });
    }

    public function down(): void {}
};
