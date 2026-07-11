<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('curriculum_coverage', function (Blueprint $table) {
            $table->boolean('is_deleted')->default(false)->after('completed');
            $table->timestamp('deleted_at')->nullable()->after('created_at');
            $table->uuid('deleted_by')->nullable()->after('deleted_at');
            $table->unique('record_of_work_id', 'coverage_record_of_work_unique');
            $table->index(['school_id', 'teacher_assignment_id', 'completed', 'is_deleted'], 'coverage_assignment_idx');
        });
    }

    public function down(): void
    {
        Schema::table('curriculum_coverage', function (Blueprint $table) {
            $table->dropUnique('coverage_record_of_work_unique');
            $table->dropIndex('coverage_assignment_idx');
            $table->dropColumn(['is_deleted', 'deleted_at', 'deleted_by']);
        });
    }
};
