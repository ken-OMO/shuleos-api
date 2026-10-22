<?php

namespace App\Services\Assessment;

use App\Models\Exam;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

class ExamResultLifecycleService
{
    /**
     * Acquire this lock before locking results, batches or generated outputs.
     * The caller must retain the transaction until all its writes finish.
     */
    public function lockExam(string $schoolId, string $examId, bool $forWrite = false): Exam
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('Exam lifecycle operations require a transaction.');
        }

        // Always acquire school access before exam/result locks.
        if ($forWrite) {
            $this->assertSchoolWritable($schoolId);
        } else {
            // Read-only reports and captured-rule reads remain available.
            DB::table('schools')->where('id', $schoolId)
                ->sharedLock()->value('id');
        }

        $exam = Exam::withoutGlobalScopes()
            ->whereKey($examId)
            ->where('school_id', $schoolId)
            ->where('is_deleted', false)
            ->lockForUpdate()
            ->first();

        if (! $exam) {
            throw ValidationException::withMessages([
                'exam_id' => 'The exam does not belong to this school.',
            ]);
        }

        return $exam;
    }

    /**
     * Check school write access and retain school/exam locks through writes.
     */
    public function lockExamForWrite(string $schoolId, string $examId): Exam
    {
        return $this->lockExam($schoolId, $examId, true);
    }

    public function assertSchoolWritable(string $schoolId): void
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('School write checks require a transaction.');
        }

        // Retain this shared lock until the caller's transaction completes.
        // Lifecycle transitions acquire an exclusive lock on the same school.
        $school = DB::table('schools')->where('id', $schoolId)
            ->sharedLock()->first();

        if (! $school) {
            throw ValidationException::withMessages([
                'school_id' => 'The school does not exist.',
            ]);
        }

        if (! $school->active || $school->is_deleted
            || in_array($school->lifecycle_state, [
                'read_only', 'locked', 'suspended', 'archived',
            ], true)) {
            throw new AuthorizationException(
                'Assessment changes are unavailable for this school.'
            );
        }

    }

    /**
     * Published outputs freeze source results for the whole exam because
     * a source change can affect positions outside the selected stream.
     */
    public function assertMutable(Exam $exam): void
    {
        if ($exam->status === 'closed') {
            throw ValidationException::withMessages([
                'exam_id' => 'Closed exam results cannot be changed.',
            ]);
        }

        foreach (['merit_lists', 'report_cards'] as $table) {
            // Include deleted published rows: deletion must not bypass the freeze.
            if (DB::table($table)
                ->where('school_id', $exam->school_id)
                ->where('exam_id', $exam->id)
                ->where('status', 'published')
                ->exists()) {
                throw ValidationException::withMessages([
                    'results' => 'Exam results cannot be changed after merit lists or report cards have been published.',
                ]);
            }
        }
    }

    public function invalidateLearningArea(
        Exam $exam,
        string $learnerId,
        string $learningAreaId
    ): void {
        DB::table('learning_area_results')
            ->where('school_id', $exam->school_id)
            ->where('exam_id', $exam->id)
            ->where('learner_id', $learnerId)
            ->where('learning_area_id', $learningAreaId)
            ->where('is_deleted', false)
            ->update(['processing_status' => 'stale']);

        $this->invalidateOutputs($exam);
    }

    public function invalidateOutputs(Exam $exam): void
    {
        foreach (['merit_lists', 'report_cards'] as $table) {
            DB::table($table)
                ->where('school_id', $exam->school_id)
                ->where('exam_id', $exam->id)
                ->where('status', 'generated')
                ->update(['status' => 'stale']);
        }
    }

    public function assertProcessedResultsFresh(Exam $exam): void
    {
        if (DB::table('learning_area_results')
            ->where('school_id', $exam->school_id)
            ->where('exam_id', $exam->id)
            ->where('is_deleted', false)
            ->where('processing_status', 'stale')
            ->exists()) {
            throw ValidationException::withMessages([
                'results' => 'Changed paper results must be reprocessed before generation or publication.',
            ]);
        }
    }
}
