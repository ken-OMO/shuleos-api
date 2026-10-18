<?php

namespace App\Services\Assessment;

use App\Models\Grade;
use App\Models\Learner;
use App\Models\Stream;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ExamMembershipService
{
    /**
     * Resolve assessment membership without saving or changing the source learner.
     * Call within the transaction holding the exam lifecycle lock.
     */
    public function learnerAtExam(
        string $schoolId,
        string $examId,
        Learner $learner
    ): Learner {
        if (DB::transactionLevel() < 1) {
            throw new \LogicException('Exam membership resolution requires a transaction.');
        }

        if ($learner->school_id !== $schoolId) {
            throw ValidationException::withMessages([
                'learner_id' => 'The learner does not belong to this school.',
            ]);
        }

        $roster = DB::table('exam_rosters')
            ->where('school_id', $schoolId)
            ->where('exam_id', $examId)
            ->first();

        $copy = clone $learner;

        // Legacy processing remains possible before an explicit roster is established.
        // Output generation independently requires a complete configured roster.
        if (! $roster) {
            return $copy;
        }

        $participant = DB::table('exam_roster_learners')
            ->where('roster_id', $roster->id)
            ->where('school_id', $schoolId)
            ->where('exam_id', $examId)
            ->where('learner_id', $learner->id)
            ->first();

        if (! $participant) {
            throw ValidationException::withMessages([
                'learner_id' => 'The learner is not included in this exam roster.',
            ]);
        }

        $grade = Grade::query()
            ->where('school_id', $schoolId)
            ->with('educationLevel')
            ->find($participant->grade_id);

        $stream = $participant->stream_id === null ? null : Stream::query()
            ->where('school_id', $schoolId)
            ->where('grade_id', $participant->grade_id)
            ->find($participant->stream_id);

        if (! $grade || ($participant->stream_id !== null && ! $stream)) {
            throw ValidationException::withMessages([
                'roster' => 'The exam roster membership no longer resolves to a valid school grade and stream.',
            ]);
        }

        $copy->grade_id = $participant->grade_id;
        $copy->stream_id = $participant->stream_id;
        $copy->setRelation('grade', $grade);
        $copy->setRelation('stream', $stream);

        return $copy;
    }
}
