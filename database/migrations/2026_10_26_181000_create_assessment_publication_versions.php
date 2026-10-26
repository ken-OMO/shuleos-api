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
        Schema::create('assessment_publication_versions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('school_id');
            $table->uuid('exam_id');
            $table->uuid('learner_id');
            $table->string('output_type', 20);
            $table->uuid('output_id');
            $table->integer('revision_number');
            $table->uuid('reissue_request_id')->nullable();
            $table->jsonb('payload');
            $table->char('payload_sha256', 64);
            $table->timestamp('published_at');
            $table->uuid('published_by')->nullable();
            $table->timestamp('recorded_at');

            $table->foreign('school_id')->references('id')->on('schools');
            $table->foreign('exam_id')->references('id')->on('exams');
            $table->foreign('learner_id')->references('id')->on('learners');
            $table->foreign('reissue_request_id')
                ->references('id')->on('assessment_reissue_requests');
            $table->foreign('published_by')->references('id')->on('users');

            $table->unique(
                ['school_id', 'output_type', 'output_id', 'revision_number'],
                'assessment_publication_version_unique'
            );

            $table->index(
                ['school_id', 'exam_id', 'learner_id'],
                'assessment_publication_learner_idx'
            );

            $table->index(
                ['reissue_request_id'],
                'assessment_publication_request_idx'
            );
        });

        DB::statement(
            'ALTER TABLE assessment_publication_versions '
            .'ADD CONSTRAINT assessment_publication_type_check '
            ."CHECK (output_type IN ('report_card', 'merit_list'))"
        );

        DB::statement(
            'ALTER TABLE assessment_publication_versions '
            .'ADD CONSTRAINT assessment_publication_revision_check '
            .'CHECK (revision_number >= 1)'
        );

        DB::statement(
            'ALTER TABLE assessment_publication_versions '
            .'ADD CONSTRAINT assessment_publication_request_check '
            .'CHECK (revision_number = 1 OR reissue_request_id IS NOT NULL)'
        );

        DB::statement(
            'ALTER TABLE assessment_publication_versions '
            .'ADD CONSTRAINT assessment_publication_payload_check '
            ."CHECK (jsonb_typeof(payload) = 'object')"
        );
    }

    public function down(): void
    {
        if (DB::table('assessment_publication_versions')->exists()) {
            throw new RuntimeException(
                'Published assessment versions must be preserved. Use a forward migration.'
            );
        }

        Schema::dropIfExists('assessment_publication_versions');
    }
};
