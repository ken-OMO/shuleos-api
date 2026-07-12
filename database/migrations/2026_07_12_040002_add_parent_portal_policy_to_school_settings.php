<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('school_settings', function (Blueprint $t) {
            $t->string('report_card_fee_policy')->default('open');
            $t->decimal('report_card_balance_threshold', 12, 2)->default(0);
            $t->text('report_card_restriction_message')->nullable();
            $t->boolean('report_card_allow_admin_override')->default(true);
            $t->boolean('parent_portal_show_fees')->default(true);
            $t->boolean('parent_portal_show_attendance')->default(true);
            $t->boolean('parent_portal_show_announcements')->default(true);
            $t->boolean('parent_portal_show_pathway')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table('school_settings', fn (Blueprint $t) => $t->dropColumn(['report_card_fee_policy', 'report_card_balance_threshold', 'report_card_restriction_message', 'report_card_allow_admin_override', 'parent_portal_show_fees', 'parent_portal_show_attendance', 'parent_portal_show_announcements', 'parent_portal_show_pathway']));
    }
};
