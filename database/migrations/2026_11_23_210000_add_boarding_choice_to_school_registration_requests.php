<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('school_registration_requests', function (Blueprint $table): void {
            $table->boolean('trial_boarding')->default(false);
            $table->foreignUuid('initial_admin_user_id')->nullable()->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('school_registration_requests', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('initial_admin_user_id');
            $table->dropColumn('trial_boarding');
        });
    }
};
