<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_card_access_overrides', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('school_id')->constrained('schools');
            $t->foreignUuid('learner_id')->constrained('learners');
            $t->foreignUuid('exam_id')->nullable()->constrained('exams');
            $t->foreignUuid('report_card_id')->nullable()->constrained('report_cards');
            $t->string('access_scope', 20);
            $t->boolean('access_allowed')->default(true);
            $t->text('reason')->nullable();
            $t->foreignUuid('approved_by')->constrained('users');
            $t->timestamp('expires_at')->nullable();
            $t->timestamps();
            $t->boolean('is_deleted')->default(false);
            $t->timestamp('deleted_at')->nullable();
            $t->foreignUuid('deleted_by')->nullable()->constrained('users');
            $t->index(['school_id', 'learner_id', 'is_deleted'], 'report_overrides_tenant_learner_idx');
            $t->index(['school_id', 'report_card_id', 'access_scope', 'is_deleted'], 'report_overrides_card_scope_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_card_access_overrides');
    }
};
