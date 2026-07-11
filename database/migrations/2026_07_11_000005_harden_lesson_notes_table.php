<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lesson_notes', function (Blueprint $t) {
            $t->boolean('is_deleted')->default(false)->after('created_at');
            $t->timestamp('deleted_at')->nullable()->after('is_deleted');
            $t->uuid('deleted_by')->nullable()->after('deleted_at');
            $t->unique('lesson_plan_id', 'lesson_notes_plan_unique');
            $t->index(['school_id', 'is_deleted', 'created_at'], 'lesson_notes_tenant_idx');
        });
    }

    public function down(): void
    {
        Schema::table('lesson_notes', function (Blueprint $t) {
            $t->dropUnique('lesson_notes_plan_unique');
            $t->dropIndex('lesson_notes_tenant_idx');
            $t->dropColumn(['is_deleted', 'deleted_at', 'deleted_by']);
        });
    }
};
