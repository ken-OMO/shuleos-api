<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('schemes_of_work', function (Blueprint $table) {
            $table->boolean('is_deleted')->default(false)->after('active');
            $table->timestamp('deleted_at')->nullable()->after('created_at');
            $table->uuid('deleted_by')->nullable()->after('deleted_at');
            $table->index(['school_id', 'academic_year_id', 'term_id'], 'sow_school_period_idx');
            $table->index(['grade_id', 'learning_area_id', 'active', 'is_deleted'], 'sow_curriculum_idx');
        });
    }

    public function down(): void
    {
        Schema::table('schemes_of_work', function (Blueprint $table) {
            $table->dropIndex('sow_school_period_idx');
            $table->dropIndex('sow_curriculum_idx');
            $table->dropColumn(['is_deleted', 'deleted_at', 'deleted_by']);
        });
    }
};
