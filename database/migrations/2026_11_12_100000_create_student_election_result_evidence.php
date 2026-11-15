<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $duplicates = DB::table('student_leadership_terms')->whereNotNull('election_id')
            ->where('appointment_type', 'elected')->select('election_id', 'position_id', 'learner_id')
            ->groupBy('election_id', 'position_id', 'learner_id')->havingRaw('COUNT(*) > 1')->exists();
        if ($duplicates) {
            throw new RuntimeException('Existing duplicate elected appointments require reviewed adoption.');
        }
        Schema::create('student_election_tally_snapshots', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools');
            $table->foreignUuid('election_id')->unique()->constrained('student_elections');
            $table->string('digest', 64);
            $table->jsonb('payload');
            $table->foreignUuid('created_by')->constrained('users');
            $table->timestamp('created_at');
        });
        Schema::create('student_election_declarations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools');
            $table->foreignUuid('election_id')->unique()->constrained('student_elections');
            $table->foreignUuid('tally_snapshot_id')->unique()->constrained('student_election_tally_snapshots');
            $table->string('digest', 64);
            $table->jsonb('term_ids');
            $table->date('starts_at');
            $table->foreignUuid('created_by')->constrained('users');
            $table->timestamp('created_at');
        });
        DB::statement("CREATE UNIQUE INDEX student_elected_term_once ON student_leadership_terms (election_id, position_id, learner_id) WHERE election_id IS NOT NULL AND appointment_type = 'elected'");
    }

    public function down(): void
    {
        throw new RuntimeException('Election outcome evidence requires a reviewed forward migration.');
    }
};
