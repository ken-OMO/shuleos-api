<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('school_registration_requests', function (Blueprint $table): void {
            $table->foreignUuid('requested_package_id')->nullable()->constrained('subscription_packages')->restrictOnDelete();
            $table->boolean('trial_elections')->default(false);
            $table->boolean('trial_transport')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('school_registration_requests', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('requested_package_id');
            $table->dropColumn(['trial_elections', 'trial_transport']);
        });
    }
};
