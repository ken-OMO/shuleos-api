<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lesson_plans', function (Blueprint $t) {
            $t->boolean('is_deleted')->default(false)->after('status');
            $t->timestamp('deleted_at')->nullable()->after('created_at');
            $t->uuid('deleted_by')->nullable()->after('deleted_at');
            $t->index(['school_id', 'lesson_date', 'status', 'is_deleted'], 'lesson_plans_school_date_idx');
        });
    }

    public function down(): void
    {
        Schema::table('lesson_plans', function (Blueprint $t) {
            $t->dropIndex('lesson_plans_school_date_idx');
            $t->dropColumn(['is_deleted', 'deleted_at', 'deleted_by']);
        });
    }
};
