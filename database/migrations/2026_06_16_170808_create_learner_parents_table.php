<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('learner_parents', function (
            Blueprint $table
        ) {

            $table->uuid('id')->primary();

            $table->uuid('learner_id');

            $table->uuid('parent_id');

            $table->boolean(
                'is_primary_contact'
            )->default(false);

            $table->boolean(
                'active'
            )->default(true);

            $table->timestamp(
                'created_at'
            )->useCurrent();

            // Foreign keys

            $table->foreign('learner_id')
                ->references('id')
                ->on('learners');

            $table->foreign('parent_id')
                ->references('id')
                ->on('parents');

            // Prevent duplicates

            $table->unique([

                'learner_id',

                'parent_id'

            ]);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists(
            'learner_parents'
        );
    }
};
