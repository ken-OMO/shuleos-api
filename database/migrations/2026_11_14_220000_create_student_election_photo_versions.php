<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw new RuntimeException('PostgreSQL is required.');
        }
        Schema::create('student_election_photo_versions', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('school_id')->constrained('schools');
            $t->foreignUuid('learner_id')->constrained('learners');
            $t->unsignedInteger('revision');
            $t->char('storage_id', 64)->unique();
            $t->char('source_hash', 64);
            $t->char('stored_hash', 64);
            $t->unsignedInteger('width');
            $t->unsignedInteger('height');
            $t->string('mime')->default('image/png');
            $t->string('scan_basis', 32);
            $t->string('scanner', 120);
            $t->foreignUuid('attested_by')->constrained('users');
            $t->timestampTz('created_at')->useCurrent();
            $t->unique(['school_id', 'learner_id', 'revision'], 'election_photo_revision');
            $t->unique(['school_id', 'learner_id', 'id'], 'election_photo_owner');
        });
        Schema::create('student_election_profile_photos', function (Blueprint $t) {
            $t->foreignUuid('school_id')->constrained('schools');
            $t->foreignUuid('learner_id')->constrained('learners');
            $t->foreignUuid('version_id')->constrained('student_election_photo_versions');
            $t->primary(['school_id', 'learner_id']);
            $t->foreign(['school_id', 'learner_id', 'version_id'], 'election_profile_photo_owner')
                ->references(['school_id', 'learner_id', 'id'])->on('student_election_photo_versions');
        });
        Schema::table('student_election_application_versions', function (Blueprint $t) {
            $t->foreignUuid('photo_version_id')->nullable()->constrained('student_election_photo_versions');
        });
        Schema::table('student_election_candidates', function (Blueprint $t) {
            $t->uuid('photo_version_id')->nullable();
            $t->foreign(['school_id', 'learner_id', 'photo_version_id'], 'election_candidate_photo_owner')
                ->references(['school_id', 'learner_id', 'id'])->on('student_election_photo_versions');
        });
        DB::statement("ALTER TABLE student_election_photo_versions ADD CONSTRAINT election_photo_shape CHECK (revision > 0 AND mime = 'image/png' AND width BETWEEN 1 AND 1024 AND height BETWEEN 1 AND 1024 AND scan_basis IN ('production_scan','test_only') AND storage_id ~ '^[a-f0-9]{64}$' AND source_hash ~ '^[a-f0-9]{64}$' AND stored_hash ~ '^[a-f0-9]{64}$')");
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION election_photo_immutable() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF TG_OP <> 'INSERT' THEN
        RAISE EXCEPTION 'Election profile photos are immutable';
    END IF;
    IF NOT EXISTS (SELECT 1 FROM learners WHERE id = NEW.learner_id AND school_id = NEW.school_id)
       OR NOT EXISTS (SELECT 1 FROM users WHERE id = NEW.attested_by AND school_id = NEW.school_id) THEN
        RAISE EXCEPTION 'Election photo tenant scope mismatch';
    END IF;
    RETURN NEW;
END;
$$;
CREATE TRIGGER election_photo_immutable BEFORE INSERT OR UPDATE OR DELETE ON student_election_photo_versions FOR EACH ROW EXECUTE FUNCTION election_photo_immutable();
SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Photo evidence requires a reviewed forward migration.');
    }
};
