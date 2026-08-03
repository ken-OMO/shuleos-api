<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('exam_results', function (Blueprint $t) {
            $t->boolean('is_deleted')->default(false)->after('marks');
            $t->timestamp('deleted_at')->nullable()->after('created_at');
            $t->uuid('deleted_by')->nullable()->after('deleted_at');
            $t->index(['exam_id', 'learner_id', 'is_deleted'], 'exam_results_learner_idx');
            $t->index(['paper_id', 'is_deleted'], 'exam_results_paper_idx');
        });
    }

    public function down(): void
    {
        Schema::table('exam_results', function (Blueprint $t) {
            $t->dropIndex('exam_results_learner_idx');
            $t->dropIndex('exam_results_paper_idx');
            $t->dropColumn(['is_deleted', 'deleted_at', 'deleted_by']);
        });
    }
};
