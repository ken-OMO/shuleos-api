<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('learners', function (Blueprint $t) {
            $t->foreignUuid('user_id')->nullable()->unique()->constrained('users');
            $t->boolean('portal_enabled')->default(true);
            $t->timestamp('portal_activated_at')->nullable();
            $t->index(['school_id', 'user_id']);
            $t->index(['school_id', 'active', 'portal_enabled']);
        });
    }

    public function down(): void
    {
        Schema::table('learners', function (Blueprint $t) {
            $t->dropIndex(['school_id', 'user_id']);
            $t->dropIndex(['school_id', 'active', 'portal_enabled']);
            $t->dropConstrainedForeignId('user_id');
            $t->dropColumn(['portal_enabled', 'portal_activated_at']);
        });
    }
};
