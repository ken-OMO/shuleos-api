<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void { Schema::table('scheme_lessons', function (Blueprint $table) {
        $table->boolean('is_deleted')->default(false)->after('created_at');
        $table->timestamp('deleted_at')->nullable()->after('is_deleted');
        $table->uuid('deleted_by')->nullable()->after('deleted_at');
        $table->index(['week_id', 'is_deleted'], 'scheme_lessons_week_current_idx');
    }); }
    public function down(): void { Schema::table('scheme_lessons', function (Blueprint $table) {
        $table->dropIndex('scheme_lessons_week_current_idx');
        $table->dropColumn(['is_deleted', 'deleted_at', 'deleted_by']);
    }); }
};
