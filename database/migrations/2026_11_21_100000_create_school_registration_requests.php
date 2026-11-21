<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('school_registration_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('school_name');
            $table->string('operating_mode', 20);
            $table->string('admin_first_name', 100);
            $table->string('admin_last_name', 100);
            $table->string('admin_email');
            $table->string('email')->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('postal_address')->nullable();
            $table->string('physical_address')->nullable();
            $table->string('status', 20)->default('pending');
            $table->foreignUuid('school_id')->nullable()->constrained('schools');
            $table->foreignUuid('reviewed_by')->nullable()->constrained('users');
            $table->text('review_reason')->nullable();
            $table->timestampTz('reviewed_at')->nullable();
            $table->timestampsTz();
            $table->index(['status', 'created_at']);
        });
        DB::statement("CREATE UNIQUE INDEX school_registration_pending_email_unique ON school_registration_requests (admin_email) WHERE status = 'pending'");
        DB::statement("ALTER TABLE school_registration_requests ADD CONSTRAINT school_registration_status_check CHECK (status IN ('pending', 'approved', 'rejected'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('school_registration_requests');
    }
};
