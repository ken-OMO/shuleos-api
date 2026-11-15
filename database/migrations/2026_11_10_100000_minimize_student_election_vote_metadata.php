<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw new RuntimeException('PostgreSQL is required for election metadata migration.');
        }
        DB::statement('LOCK TABLE student_elections, student_election_voters, student_election_ballots IN ACCESS EXCLUSIVE MODE');
        // Never silently discard existing election evidence. An installation
        // with voting history requires a separately reviewed adoption plan.
        if (DB::table('student_elections')->where('status', 'voting_open')->exists()
            || DB::table('student_election_ballots')->exists()
            || DB::table('student_election_voters')->where('has_voted', true)
                ->orWhereNotNull('voted_at')->orWhereNotNull('voting_token_hash')->exists()) {
            throw new RuntimeException('Existing election voting evidence requires reviewed adoption; no metadata removed.');
        }
        Schema::table('student_election_ballots', function (Blueprint $table): void {
            $table->dropColumn(['cast_at', 'created_at']);
        });
        Schema::table('student_election_voters', function (Blueprint $table): void {
            $table->dropColumn(['voted_at', 'voting_token_hash']);
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Vote metadata cannot be reconstructed. Use a reviewed forward migration.');
    }
};
