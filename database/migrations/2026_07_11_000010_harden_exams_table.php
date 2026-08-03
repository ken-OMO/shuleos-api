<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('exams', function (Blueprint $t) {
            $t->string('status', 20)->default('draft')->after('active');
            $t->boolean('is_deleted')->default(false)->after('status');
            $t->timestamp('deleted_at')->nullable()->after('created_at');
            $t->uuid('deleted_by')->nullable()->after('deleted_at');
            $t->index(['school_id', 'academic_year_id', 'term_id', 'status', 'is_deleted'], 'exams_period_idx');
        });
    }

    public function down(): void
    {
        Schema::table('exams', function (Blueprint $t) {
            $t->dropIndex('exams_period_idx');
            $t->dropColumn(['status', 'is_deleted', 'deleted_at', 'deleted_by']);
        });
    }
};
