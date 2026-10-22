<?php

namespace App\Services\Assessment;

use App\Models\ExamLearningArea;
use App\Models\LearningArea;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ExamLearningAreaService
{
    public function create(array $d, string $school): ExamLearningArea
    {
        return DB::transaction(function () use ($d, $school) {
            app(ExamResultLifecycleService::class)->assertSchoolWritable($school);

            return $this->createWritable($d, $school);
        });
    }

    public function update(
        ExamLearningArea $area, array $data, string $school
    ): ExamLearningArea {
        return DB::transaction(function () use ($area, $data, $school) {
            $locked = $this->lockEditable($area, $school);

            $values = Validator::make($data, [
                'number_of_papers' => 'sometimes|integer|min:1|max:10',
                'total_marks' => 'sometimes|integer|min:1|max:1000',
            ])->validate();

            $locked->update($values);

            return $locked;
        });
    }

    public function delete(
        ExamLearningArea $area, string $school, ?string $actor
    ): void {
        DB::transaction(function () use ($area, $school, $actor) {
            $locked = $this->lockEditable($area, $school);
            $locked->update([
                'is_deleted' => true,
                'deleted_at' => now(),
                'deleted_by' => $actor,
            ]);
        });
    }

    private function lockEditable(
        ExamLearningArea $area, string $school
    ): ExamLearningArea {
        $exam = app(ExamResultLifecycleService::class)->lockExamForWrite(
            $school, $area->exam_id
        );

        $locked = ExamLearningArea::withoutGlobalScopes()->current()
            ->whereKey($area->id)->where('exam_id', $exam->id)
            ->lockForUpdate()->first();

        abort_if(! $locked, 404, 'Exam learning area not found.');
        abort_if(
            $exam->status !== 'draft' || $locked->papers()->exists(),
            400,
            'Exam learning areas with papers or a published exam cannot be changed.'
        );

        return $locked;
    }

    private function createWritable(array $d, string $school): ExamLearningArea
    {
        $exam = app(ExamResultLifecycleService::class)->lockExamForWrite(
            $school, $d['exam_id']
        );
        if ($exam->status !== 'draft' || ! $exam->active) {
            throw ValidationException::withMessages([
                'exam_learning_area' => 'The exam must be an active tenant draft.',
            ]);
        }
        $area = LearningArea::whereKey($d['learning_area_id'])->where('active', true)->first();
        if (! $exam || ! $area) {
            throw ValidationException::withMessages(['exam_learning_area' => 'The exam must be an active tenant draft and the learning area must be active.']);
        }if (ExamLearningArea::current()->where('exam_id', $exam->id)->where('learning_area_id', $area->id)->exists()) {
            throw ValidationException::withMessages(['learning_area_id' => 'This learning area is already attached to the exam.']);
        }

        return ExamLearningArea::create([...$d, 'id' => (string) Str::uuid(), 'is_deleted' => false, 'created_at' => now()]);
    }
}
