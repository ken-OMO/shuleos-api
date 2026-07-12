<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('learner_parents', function (Blueprint $t) {
            $t->string('relationship')->nullable();
            $t->boolean('receives_sms')->default(true);
            $t->boolean('receives_email')->default(true);
            $t->boolean('receives_report_cards')->default(true);
            $t->boolean('portal_enabled')->default(true);
            $t->boolean('emergency_contact')->default(false);
            $t->boolean('can_pick_learner')->default(false);
            $t->foreignUuid('linked_by')->nullable()->constrained('users');
            $t->timestamp('linked_at')->nullable();
            $t->timestamp('updated_at')->nullable();
            $t->boolean('is_deleted')->default(false);
            $t->timestamp('deleted_at')->nullable();
            $t->foreignUuid('deleted_by')->nullable()->constrained('users');
            $t->index(['parent_id', 'active', 'portal_enabled'], 'learner_parents_portal_idx');
            $t->index(['learner_id', 'active'], 'learner_parents_learner_active_idx');
            $t->index(['parent_id', 'learner_id', 'is_deleted'], 'learner_parents_current_idx');
        });
    }

    public function down(): void
    {
        Schema::table('learner_parents', function (Blueprint $t) {
            $t->dropIndex('learner_parents_portal_idx');
            $t->dropIndex('learner_parents_learner_active_idx');
            $t->dropIndex('learner_parents_current_idx');
            $t->dropConstrainedForeignId('linked_by');
            $t->dropConstrainedForeignId('deleted_by');
            $t->dropColumn(['relationship', 'receives_sms', 'receives_email', 'receives_report_cards', 'portal_enabled', 'emergency_contact', 'can_pick_learner', 'linked_at', 'updated_at', 'is_deleted', 'deleted_at']);
        });
    }
};
