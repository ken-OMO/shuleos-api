<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assessment_types', function (Blueprint $t) {
            $t->boolean('is_deleted')->default(false)->after('active');
            $t->timestamp('deleted_at')->nullable()->after('created_at');
            $t->uuid('deleted_by')->nullable()->after('deleted_at');
            $t->index(['school_id', 'active', 'is_deleted'], 'assessment_types_tenant_idx');
        });
    }

    public function down(): void
    {
        Schema::table('assessment_types', function (Blueprint $t) {
            $t->dropIndex('assessment_types_tenant_idx');
            $t->dropColumn(['is_deleted', 'deleted_at', 'deleted_by']);
        });
    }
};
