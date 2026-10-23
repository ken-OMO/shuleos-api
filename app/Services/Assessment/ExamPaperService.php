<?php

namespace App\Services\Assessment;

use App\Models\ExamLearningArea;
use App\Models\ExamPaper;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ExamPaperService
{
    public function create(array $d, string $school): ExamPaper
    {
        return DB::transaction(function () use ($d, $school) {
            app(ExamResultLifecycleService::class)->assertSchoolWritable($school);

            return $this->createWritable($d, $school);
        });
    }

    public function update(
        ExamPaper $paper, array $data, string $school
    ): ExamPaper {
        return DB::transaction(function () use ($paper, $data, $school) {
            [$locked, $area] = $this->lockEditable($paper, $school);

            $values = Validator::make($data, [
                'paper_name' => 'sometimes|string|max:255',
                'max_marks' => 'sometimes|integer|min:1|max:1000',
            ])->validate();

            if (isset($values['max_marks'])) {
                $other = ExamPaper::withoutGlobalScopes()->current()
                    ->where('exam_learning_area_id', $area->id)
                    ->whereKeyNot($locked->id)->sum('max_marks');

                if ($other + $values['max_marks'] > $area->total_marks) {
                    throw ValidationException::withMessages([
                        'max_marks' => 'Paper marks would exceed the learning area total marks.',
                    ]);
                }
            }

            $locked->update($values);

            return $locked;
        });
    }

    public function delete(
        ExamPaper $paper, string $school, ?string $actor
    ): void {
        DB::transaction(function () use ($paper, $school, $actor) {
            [$locked] = $this->lockEditable($paper, $school);
            $locked->update([
                'is_deleted' => true,
                'deleted_at' => now(),
                'deleted_by' => $actor,
            ]);
        });
    }

    private function lockEditable(ExamPaper $paper, string $school): array
    {
        $candidate = ExamLearningArea::withoutGlobalScopes()->current()
            ->whereKey($paper->exam_learning_area_id)
            ->whereHas('exam', fn ($q) => $q
                ->where('school_id', $school)->where('is_deleted', false))
            ->first();

        abort_if(! $candidate, 404, 'Exam paper not found.');

        $exam = app(ExamResultLifecycleService::class)->lockExamForWrite(
            $school, $candidate->exam_id
        );

        $area = ExamLearningArea::withoutGlobalScopes()->current()
            ->whereKey($candidate->id)->where('exam_id', $exam->id)
            ->lockForUpdate()->first();

        abort_if(! $area, 404, 'Exam paper not found.');

        $locked = ExamPaper::withoutGlobalScopes()->current()
            ->whereKey($paper->id)->where('exam_learning_area_id', $area->id)
            ->lockForUpdate()->first();

        abort_if(! $locked, 404, 'Exam paper not found.');
        abort_if(
            $exam->status !== 'draft' || $locked->results()->exists(),
            400,
            'Papers with results or a published exam cannot be changed.'
        );

        return [$locked, $area];
    }

    private function createWritable(array $d, string $school): ExamPaper
    {
        $candidate = ExamLearningArea::withoutGlobalScopes()->current()
            ->whereKey($d['exam_learning_area_id'])
            ->whereHas('exam', fn ($q) => $q
                ->where('school_id', $school)->where('is_deleted', false))
            ->first();

        if (! $candidate) {
            throw ValidationException::withMessages([
                'exam_learning_area_id' => 'The exam learning area must belong to an active tenant draft exam.',
            ]);
        }

        $exam = app(ExamResultLifecycleService::class)->lockExamForWrite(
            $school, $candidate->exam_id
        );

        // Re-read after acquiring the exam lock; the candidate may be stale.
        $area = ExamLearningArea::withoutGlobalScopes()->current()
            ->whereKey($candidate->id)
            ->where('exam_id', $exam->id)->lockForUpdate()->first();

        if ($exam->status !== 'draft' || ! $exam->active) {
            throw ValidationException::withMessages([
                'exam_learning_area_id' => 'The exam learning area must belong to an active tenant draft exam.',
            ]);
        }
        if (! $area) {
            throw ValidationException::withMessages(['exam_learning_area_id' => 'The exam learning area must belong to an active tenant draft exam.']);
        }$papers = ExamPaper::current()->where('exam_learning_area_id', $area->id);
        if ((clone $papers)->where('paper_number', $d['paper_number'])->exists()) {
            throw ValidationException::withMessages(['paper_number' => 'This paper number already exists for the learning area.']);
        }if ((clone $papers)->count() >= $area->number_of_papers) {
            throw ValidationException::withMessages(['paper_number' => 'The declared number of papers has already been reached.']);
        }if ((clone $papers)->sum('max_marks') + $d['max_marks'] > $area->total_marks) {
            throw ValidationException::withMessages(['max_marks' => 'Paper marks would exceed the learning area total marks.']);
        }

        return ExamPaper::create([...$d, 'id' => (string) Str::uuid(), 'paper_name' => trim($d['paper_name']), 'is_deleted' => false, 'created_at' => now()]);
    }
}
