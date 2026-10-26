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
        Schema::create('assessment_reissue_drafts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('request_id');
            $table->uuid('school_id');
            $table->uuid('exam_id');
            $table->uuid('learner_id');
            $table->string('output_type', 30);
            $table->uuid('output_id');
            $table->integer('revision_number')->nullable();
            $table->char('source_sha256', 64);
            $table->jsonb('payload');
            $table->char('payload_sha256', 64);
            $table->uuid('prepared_by');
            $table->timestamp('created_at');

            $table->foreign('request_id')
                ->references('id')->on('assessment_reissue_requests');
            $table->foreign('school_id')->references('id')->on('schools');
            $table->foreign('exam_id')->references('id')->on('exams');
            $table->foreign('learner_id')->references('id')->on('learners');
            $table->foreign('prepared_by')->references('id')->on('users');

            $table->unique(
                ['request_id', 'output_type', 'output_id'],
                'assessment_reissue_draft_output_unique'
            );

            $table->index(
                ['school_id', 'exam_id', 'learner_id'],
                'assessment_reissue_draft_learner_idx'
            );
        });

        DB::statement(
            'ALTER TABLE assessment_reissue_drafts '
            .'ADD CONSTRAINT assessment_reissue_draft_type_check '
            ."CHECK (output_type IN ('paper_result', 'learning_area_result', 'merit_list', 'report_card'))"
        );

        DB::statement(
            'ALTER TABLE assessment_reissue_drafts '
            .'ADD CONSTRAINT assessment_reissue_draft_revision_check '
            ."CHECK ((output_type IN ('paper_result', 'learning_area_result') AND revision_number IS NULL) "
            ."OR (output_type IN ('merit_list', 'report_card') AND revision_number IS NOT NULL AND revision_number >= 1))"
        );

        DB::statement(
            'ALTER TABLE assessment_reissue_drafts '
            .'ADD CONSTRAINT assessment_reissue_draft_payload_check '
            ."CHECK (jsonb_typeof(payload) = 'object')"
        );
    }

    public function down(): void
    {
        if (DB::table('assessment_reissue_drafts')->exists()) {
            throw new RuntimeException(
                'Assessment revision drafts must be preserved. Use a forward migration.'
            );
        }

        Schema::dropIfExists('assessment_reissue_drafts');
    }
};
