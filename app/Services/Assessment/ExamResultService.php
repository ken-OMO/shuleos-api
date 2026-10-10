<?php

namespace App\Services\Assessment;

use App\Models\ExamPaper;
use App\Models\ExamResult;
use App\Models\Learner;
use App\Models\MarkEntryPermission;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ExamResultService
{
    public function __construct(private readonly ExamResultLifecycleService $lifecycle) {}

    public function create(array $data, string $schoolId, ?string $userId): ExamResult
    {
        return DB::transaction(function () use ($data, $schoolId, $userId) {
            $paper = ExamPaper::current()
                ->with('examLearningArea')
                ->whereKey($data['paper_id'])
                ->whereHas('examLearningArea.exam', fn ($query) => $query
                    ->where('school_id', $schoolId)
                    ->where('is_deleted', false))
                ->first();

            if (! $paper) {
                throw ValidationException::withMessages([
                    'result' => 'The learner or published exam paper is unavailable outside this school.',
                ]);
            }

            $exam = $this->lifecycle->lockExam($schoolId, $paper->examLearningArea->exam_id);
            $this->lifecycle->assertMutable($exam);

            $result = $this->createValidated($data, $schoolId, $userId);
            $this->lifecycle->invalidateLearningArea(
                $exam,
                $result->learner_id,
                $result->learning_area_id
            );

            return $result;
        });
    }

    public function updateMarks(
        string $schoolId,
        string $resultId,
        float $marks,
        ?string $userId
    ): ExamResult {
        return DB::transaction(function () use ($schoolId, $resultId, $marks, $userId) {
            [$exam, $result] = $this->lockResult($schoolId, $resultId);
            $this->assertMarkEntryPermission($exam->id, $userId);

            $paper = ExamPaper::current()->find($result->paper_id);
            if (! $paper || $marks < 0 || $marks > (float) $paper->max_marks) {
                throw ValidationException::withMessages([
                    'marks' => 'Marks must be within the available paper maximum.',
                ]);
            }

            if ((float) $result->marks !== round($marks, 2)) {
                $result->update(['marks' => $marks]);
                $this->lifecycle->invalidateLearningArea(
                    $exam,
                    $result->learner_id,
                    $result->learning_area_id
                );
            }

            return $result;
        });
    }

    public function deleteResult(string $schoolId, string $resultId, ?string $userId): void
    {
        DB::transaction(function () use ($schoolId, $resultId, $userId) {
            [$exam, $result] = $this->lockResult($schoolId, $resultId);
            $this->assertMarkEntryPermission($exam->id, $userId);

            $result->update([
                'is_deleted' => true,
                'deleted_at' => now(),
                'deleted_by' => $userId,
            ]);

            $this->lifecycle->invalidateLearningArea(
                $exam,
                $result->learner_id,
                $result->learning_area_id
            );
        });
    }

    private function lockResult(string $schoolId, string $resultId): array
    {
        $candidate = ExamResult::current()
            ->whereKey($resultId)
            ->whereHas('exam', fn ($query) => $query
                ->where('school_id', $schoolId)
                ->where('is_deleted', false))
            ->first();

        if (! $candidate) {
            throw ValidationException::withMessages([
                'result' => 'Exam result not found for this school.',
            ]);
        }

        $exam = $this->lifecycle->lockExam($schoolId, $candidate->exam_id);
        $this->lifecycle->assertMutable($exam);

        $result = ExamResult::current()
            ->whereKey($resultId)
            ->where('exam_id', $exam->id)
            ->lockForUpdate()
            ->first();

        if (! $result) {
            throw ValidationException::withMessages([
                'result' => 'Exam result not found for this school.',
            ]);
        }

        return [$exam, $result];
    }

    private function createValidated(array $data, string $schoolId, ?string $userId): ExamResult
    {
        $paper = ExamPaper::current()
            ->with('examLearningArea.exam')
            ->whereKey($data['paper_id'])
            ->whereHas('examLearningArea.exam', fn ($query) => $query
                ->where('school_id', $schoolId)
                ->where('status', 'published')
                ->where('is_deleted', false))
            ->first();

        $learner = Learner::whereKey($data['learner_id'])
            ->where('school_id', $schoolId)
            ->where('active', true)
            ->where('is_deleted', false)
            ->first();

        if (! $paper || ! $learner) {
            throw ValidationException::withMessages([
                'result' => 'The learner or published exam paper is unavailable outside this school.',
            ]);
        }

        $this->assertMarkEntryPermission($paper->examLearningArea->exam_id, $userId);

        if ($data['marks'] < 0 || $data['marks'] > $paper->max_marks) {
            throw ValidationException::withMessages([
                'marks' => "Marks must be between 0 and {$paper->max_marks}.",
            ]);
        }

        if (ExamResult::current()->where('learner_id', $learner->id)->where('paper_id', $paper->id)->exists()) {
            throw ValidationException::withMessages([
                'paper_id' => 'A result already exists for this learner and paper.',
            ]);
        }

        $area = $paper->examLearningArea;

        $deletedResult = ExamResult::query()
            ->where('exam_id', $area->exam_id)
            ->where('learner_id', $learner->id)
            ->where('learning_area_id', $area->learning_area_id)
            ->where('paper_id', $paper->id)
            ->where('is_deleted', true)
            ->lockForUpdate()
            ->first();

        if ($deletedResult) {
            $deletedResult->update([
                'marks' => $data['marks'],
                'entered_by' => $userId,
                'is_deleted' => false,
                'deleted_at' => null,
                'deleted_by' => null,
            ]);

            return $deletedResult;
        }

        return ExamResult::create([
            'id' => (string) Str::uuid(),
            'exam_id' => $area->exam_id,
            'learner_id' => $learner->id,
            'learning_area_id' => $area->learning_area_id,
            'paper_id' => $paper->id,
            'marks' => $data['marks'],
            'entered_by' => $userId,
            'is_deleted' => false,
            'created_at' => now(),
        ]);
    }

    private function assertMarkEntryPermission(string $examId, ?string $userId): void
    {
        $user = $userId ? User::with('role')->find($userId) : null;
        $roleName = $user?->role?->role_name;

        $permission = $roleName
            ? MarkEntryPermission::current()
                ->where('exam_id', $examId)
                ->whereRaw('LOWER(role_name) = ?', [mb_strtolower($roleName)])
                ->where('active', true)
                ->first()
            : null;

        if (! $permission || ! $permission->isOpen()) {
            throw ValidationException::withMessages([
                'permission' => 'Mark entry permission is missing, closed, or expired.',
            ]);
        }
    }
}
