<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mark_entry_permissions', function (Blueprint $t) {
            $t->timestamp('opens_at')->nullable()->after('active');
            $t->timestamp('closes_at')->nullable()->after('opens_at');
            $t->boolean('is_deleted')->default(false)->after('closes_at');
            $t->timestamp('deleted_at')->nullable()->after('created_at');
            $t->uuid('deleted_by')->nullable()->after('deleted_at');
            $t->index(['exam_id', 'active', 'is_deleted'], 'mark_permissions_exam_idx');
        });
    }

    public function down(): void
    {
        Schema::table('mark_entry_permissions', function (Blueprint $t) {
            $t->dropIndex('mark_permissions_exam_idx');
            $t->dropColumn(['opens_at', 'closes_at', 'is_deleted', 'deleted_at', 'deleted_by']);
        });
    }
};
