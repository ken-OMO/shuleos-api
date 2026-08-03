<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('finance_audit_logs', function (Blueprint $table) {

            $table->string('ip_address')

                ->nullable()

                ->after('record_id');

            $table->text('user_agent')

                ->nullable()

                ->after('ip_address');

        });
    }

    public function down(): void
    {
        Schema::table('finance_audit_logs', function (Blueprint $table) {

            $table->dropColumn([

                'ip_address',

                'user_agent',

            ]);

        });
    }
};
