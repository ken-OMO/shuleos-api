<?php

namespace App\Services\Assessment;

use App\Models\Grade;
use App\Models\Learner;
use App\Models\Stream;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ExamMembershipService
{
    /**
     * Resolve assessment membership without saving or changing the source learner.
     * Call within the transaction holding the exam lifecycle lock.
     */
    public function learnersAtExam(
        string $schoolId,
        string $examId,
        iterable $learners
    ): Collection {
        if (DB::transactionLevel() < 1) {
            throw new \LogicException('Exam membership resolution requires a transaction.');
        }

        $sources = collect();
        foreach ($learners as $learner) {
            if (! $learner instanceof Learner || $learner->school_id !== $schoolId) {
                throw ValidationException::withMessages([
                    'learner_id' => 'Every learner must resolve to this school.',
                ]);
            }
            $sources->put($learner->id, $learner);
        }

        // Bulk output generation requires an explicit exam roster.
        $roster = DB::table('exam_rosters')
            ->where('school_id', $schoolId)->where('exam_id', $examId)->first();

        if (! $roster) {
            throw ValidationException::withMessages([
                'roster' => 'Bulk exam membership requires a configured roster.',
            ]);
        }

        $participants = DB::table('exam_roster_learners')
            ->where('roster_id', $roster->id)
            ->where('school_id', $schoolId)->where('exam_id', $examId)
            ->whereIn('learner_id', $sources->keys())
            ->get()->keyBy('learner_id');

        $grades = Grade::query()->where('school_id', $schoolId)
            ->whereIn('id', $participants->pluck('grade_id')->unique())
            ->with('educationLevel')->get()->keyBy('id');

        $streams = Stream::query()->where('school_id', $schoolId)
            ->whereIn('id', $participants->pluck('stream_id')->filter()->unique())
            ->get()->keyBy('id');

        return $sources->map(function (Learner $learner) use ($participants, $grades, $streams) {
            $participant = $participants->get($learner->id);
            if (! $participant) {
                throw ValidationException::withMessages([
                    'learner_id' => 'The learner is not included in this exam roster.',
                ]);
            }

            $grade = $grades->get($participant->grade_id);
            $stream = $participant->stream_id === null
                ? null : $streams->get($participant->stream_id);

            if (! $grade
                || ($participant->stream_id !== null
                    && (! $stream || $stream->grade_id !== $participant->grade_id))) {
                throw ValidationException::withMessages([
                    'roster' => 'The exam roster membership no longer resolves to a valid school grade and stream.',
                ]);
            }

            $copy = clone $learner;
            $copy->grade_id = $participant->grade_id;
            $copy->stream_id = $participant->stream_id;
            $copy->setRelation('grade', $grade);
            $copy->setRelation('stream', $stream);

            return $copy;
        });
    }

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
            if (! $learner->active || $learner->is_deleted) {
                throw ValidationException::withMessages([
                    'learner_id' => 'An inactive or archived learner requires existing exam roster membership.',
                ]);
            }

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
