<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('teacher_assignments', function (Blueprint $table) {
            $table->boolean('is_deleted')->default(false)->after('active');
            $table->timestamp('deleted_at')->nullable()->after('created_at');
            $table->uuid('deleted_by')->nullable()->after('deleted_at');
            $table->index(['school_id', 'academic_year_id', 'term_id'], 'ta_school_period_idx');
            $table->index(['stream_id', 'is_class_teacher', 'active', 'is_deleted'], 'ta_class_teacher_idx');
        });
    }

    public function down(): void
    {
        Schema::table('teacher_assignments', function (Blueprint $table) {
            $table->dropIndex('ta_school_period_idx');
            $table->dropIndex('ta_class_teacher_idx');
            $table->dropColumn(['is_deleted', 'deleted_at', 'deleted_by']);
        });
    }
};
