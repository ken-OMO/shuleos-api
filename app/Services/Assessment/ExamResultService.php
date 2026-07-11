<?php

namespace App\Services\Assessment;

use App\Models\ExamPaper;
use App\Models\ExamResult;
use App\Models\Learner;
use App\Models\MarkEntryPermission;
use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ExamResultService
{
    public function create(array $data, string $schoolId, ?string $userId): ExamResult
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
