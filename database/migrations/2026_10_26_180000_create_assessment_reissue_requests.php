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
        Schema::create('assessment_reissue_requests', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('school_id');
            $table->uuid('exam_id');
            $table->text('reason');
            $table->jsonb('proposed_changes');
            $table->char('proposal_sha256', 64);
            $table->char('source_sha256', 64);
            $table->string('status', 30)->default('pending');
            $table->uuid('requested_by');
            $table->uuid('decided_by')->nullable();
            $table->text('decision_reason')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->uuid('reviewed_by')->nullable();
            $table->text('review_reason')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->char('prepared_sha256', 64)->nullable();
            $table->uuid('published_by')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->foreign('school_id')->references('id')->on('schools');
            $table->foreign('exam_id')->references('id')->on('exams');

            foreach (['requested_by', 'decided_by', 'reviewed_by', 'published_by'] as $field) {
                $table->foreign($field)->references('id')->on('users');
            }

            $table->index(['school_id', 'exam_id', 'created_at'], 'assessment_reissue_exam_idx');
        });

        DB::statement(
            'ALTER TABLE assessment_reissue_requests '
            .'ADD CONSTRAINT assessment_reissue_status_check '
            ."CHECK (status IN ('pending', 'rejected', 'approved', 'prepared', 'ready', 'published', 'cancelled'))"
        );

        DB::statement(
            'ALTER TABLE assessment_reissue_requests '
            .'ADD CONSTRAINT assessment_reissue_reason_check '
            .'CHECK (length(btrim(reason)) > 0)'
        );

        DB::statement(
            'ALTER TABLE assessment_reissue_requests '
            .'ADD CONSTRAINT assessment_reissue_separate_approver_check '
            .'CHECK (decided_by IS NULL OR decided_by <> requested_by)'
        );

        DB::statement(
            'CREATE UNIQUE INDEX assessment_reissue_one_active_exam_idx '
            .'ON assessment_reissue_requests (school_id, exam_id) '
            ."WHERE status IN ('pending', 'approved', 'prepared', 'ready')"
        );

        Schema::create('assessment_reissue_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('request_id');
            $table->uuid('school_id');
            $table->uuid('actor_id');
            $table->string('action', 50);
            $table->text('reason');
            $table->jsonb('details');
            $table->timestamp('created_at');

            $table->foreign('request_id')->references('id')->on('assessment_reissue_requests');
            $table->foreign('school_id')->references('id')->on('schools');
            $table->foreign('actor_id')->references('id')->on('users');
            $table->index(['request_id', 'created_at'], 'assessment_reissue_event_history_idx');
        });

        DB::statement(
            'ALTER TABLE assessment_reissue_events '
            .'ADD CONSTRAINT assessment_reissue_event_reason_check '
            .'CHECK (length(btrim(reason)) > 0)'
        );
    }

    public function down(): void
    {
        if (DB::table('assessment_reissue_requests')->exists()
            || DB::table('assessment_reissue_events')->exists()) {
            throw new RuntimeException(
                'Assessment correction history must be preserved. Use a forward migration.'
            );
        }

        Schema::dropIfExists('assessment_reissue_events');
        Schema::dropIfExists('assessment_reissue_requests');
    }
};
