<?php

namespace App\Services\TeacherPortal;

use App\Models\MarkEntryBatch;
use App\Models\MarkEntryBatchItem;
use App\Models\User;
use App\Services\Assessment\ExamResultLifecycleService;
use App\Services\Assessment\ExamResultService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class MarkEntryBatchService
{
    public function __construct(
        private TeacherPortalAccessService $access,
        private ExamResultService $results,
        private ExamResultLifecycleService $lifecycle
    ) {}

    public function query(User $user)
    {
        $teacher = $this->access->teacher($user);

        return MarkEntryBatch::withoutGlobalScopes()->where('school_id', $user->school_id)->where('teacher_id', $teacher->id);
    }

    public function save(User $user, string $paperId, array $marks, ?string $assignmentId = null): MarkEntryBatch
    {
        [$paper, $assignment] = $this->scope($user, $paperId, $assignmentId);
        $roster = $this->roster($user, $assignment)->pluck('id');

        return DB::transaction(function () use ($user, $paper, $assignment, $marks, $roster) {
            $exam = $this->lifecycle->lockExam($user->school_id, $paper->exam_id);
            $this->lifecycle->assertMutable($exam);
            abort_unless($exam->status === 'published', 409, 'Mark entry requires a published exam.');

            $teacher = $this->access->teacher($user);
            $batch = MarkEntryBatch::withoutGlobalScopes()->where('school_id', $user->school_id)->where('exam_paper_id', $paper->id)->where('teacher_assignment_id', $assignment->id)->lockForUpdate()->first();
            if ($batch && ! in_array($batch->status, ['draft', 'reopened', 'changes_requested'], true)) {
                abort(409, 'Submitted or locked mark batches are immutable.');
            }
            $batch ??= MarkEntryBatch::create(['id' => (string) Str::uuid(), 'school_id' => $user->school_id, 'exam_id' => $paper->exam_id, 'exam_paper_id' => $paper->id, 'teacher_assignment_id' => $assignment->id, 'teacher_id' => $teacher->id, 'entered_by' => $user->id, 'expected_learner_count' => $roster->count(), 'status' => 'draft']);
            foreach ($marks as $mark) {
                abort_unless($roster->contains($mark['learner_id']), 422, 'Learner is outside this mark batch roster.');
                if ((float) $mark['marks'] < 0 || (float) $mark['marks'] > (float) $paper->max_marks) {
                    throw ValidationException::withMessages(['marks' => "Marks must be between 0 and {$paper->max_marks}."]);
                }
                $item = MarkEntryBatchItem::withoutGlobalScopes()->where('batch_id', $batch->id)->where('learner_id', $mark['learner_id'])->lockForUpdate()->first();
                if ($item) {
                    $item->update(['marks' => $mark['marks'], 'version' => $item->version + 1]);
                } else {
                    MarkEntryBatchItem::create(['id' => (string) Str::uuid(), 'school_id' => $user->school_id, 'batch_id' => $batch->id, 'learner_id' => $mark['learner_id'], 'marks' => $mark['marks']]);
                }
            }
            $batch->update(['entered_count' => MarkEntryBatchItem::withoutGlobalScopes()->where('batch_id', $batch->id)->count(), 'version' => $batch->version + 1]);

            return $batch->fresh('items');
        });
    }

    public function submit(User $user, string $batchId): MarkEntryBatch
    {
        return DB::transaction(function () use ($user, $batchId) {
            $candidate = $this->query($user)->whereKey($batchId)->firstOrFail();
            $exam = $this->lifecycle->lockExam($user->school_id, $candidate->exam_id);
            $this->lifecycle->assertMutable($exam);
            abort_unless($exam->status === 'published', 409, 'Mark submission requires a published exam.');

            $batch = $this->query($user)->whereKey($batchId)->lockForUpdate()->firstOrFail();
            abort_unless(in_array($batch->status, ['draft', 'reopened', 'changes_requested'], true), 409);
            $items = $batch->items()->lockForUpdate()->get();
            if ($items->count() !== $batch->expected_learner_count) {
                throw ValidationException::withMessages(['batch' => 'Every expected learner must have a mark before submission.']);
            }
            $paper = DB::table('exam_papers as paper')
                ->join('exam_learning_areas as area', 'area.id', '=', 'paper.exam_learning_area_id')
                ->where('paper.id', $batch->exam_paper_id)
                ->where('paper.is_deleted', false)
                ->where('area.is_deleted', false)
                ->where('area.exam_id', $exam->id)
                ->select('paper.max_marks', 'area.learning_area_id')
                ->first();

            abort_unless($paper, 409, 'The batch paper is unavailable.');

            foreach ($items as $item) {
                abort_unless($item->school_id === $user->school_id, 409, 'The batch item belongs to another school.');

                if ((float) $item->marks < 0 || (float) $item->marks > (float) $paper->max_marks) {
                    throw ValidationException::withMessages([
                        'marks' => "Marks must be between 0 and {$paper->max_marks}.",
                    ]);
                }

                if (! $item->exam_result_id) {
                    $result = $this->results->create(['paper_id' => $batch->exam_paper_id, 'learner_id' => $item->learner_id, 'marks' => $item->marks], $user->school_id, $user->id);
                    $item->update(['exam_result_id' => $result->id]);
                } elseif (in_array($batch->status, ['reopened', 'changes_requested'], true)) {
                    $result = DB::table('exam_results')
                        ->where('id', $item->exam_result_id)
                        ->where('exam_id', $exam->id)
                        ->where('paper_id', $batch->exam_paper_id)
                        ->where('learner_id', $item->learner_id)
                        ->where('learning_area_id', $paper->learning_area_id)
                        ->where('is_deleted', false)
                        ->lockForUpdate()
                        ->first();

                    abort_unless($result, 409, 'The linked result does not belong to this batch item.');

                    if ((float) $result->marks !== (float) $item->marks) {
                        $item->update(['previous_marks' => $result->marks]);
                        DB::table('exam_results')->where('id', $result->id)->update(['marks' => $item->marks]);

                        $this->lifecycle->invalidateLearningArea(
                            $exam,
                            $result->learner_id,
                            $result->learning_area_id
                        );
                    }
                }
            }
            $batch->update(['status' => 'submitted', 'submitted_at' => now(), 'version' => $batch->version + 1]);

            return $batch->fresh('items');
        });
    }

    public function correction(User $user, string $batchId, array $data)
    {
        $batch = $this->query($user)->whereKey($batchId)->whereIn('status', ['submitted', 'approved', 'locked'])->firstOrFail();
        abort_if(DB::table('mark_correction_requests')->where('batch_id', $batch->id)->where('status', 'pending')->exists(), 409, 'A correction request is already pending.');
        $item = isset($data['batch_item_id']) ? $batch->items()->whereKey($data['batch_item_id'])->firstOrFail() : null;
        if (isset($data['proposed_marks'])) {
            $maximum = DB::table('exam_papers')->where('id', $batch->exam_paper_id)->value('max_marks');
            abort_if((float) $data['proposed_marks'] > (float) $maximum, 422, 'Proposed marks exceed the paper maximum.');
        }

        $id = (string) Str::uuid();
        DB::table('mark_correction_requests')->insert(['id' => $id, 'school_id' => $user->school_id, 'batch_id' => $batch->id, 'batch_item_id' => $item?->id, 'requested_by' => $user->id, 'reason' => $data['reason'], 'previous_marks' => $item?->marks, 'proposed_marks' => $data['proposed_marks'] ?? null, 'status' => 'pending', 'created_at' => now(), 'updated_at' => now()]);

        return $id;
    }

    private function scope(User $user, string $paperId, ?string $assignmentId): array
    {
        $paper = DB::table('exam_papers as paper')->join('exam_learning_areas as area', 'area.id', '=', 'paper.exam_learning_area_id')->join('exams as exam', 'exam.id', '=', 'area.exam_id')->where('paper.id', $paperId)->where('paper.is_deleted', false)->where('exam.school_id', $user->school_id)->where('exam.status', 'published')->where('exam.is_deleted', false)->select('paper.id', 'paper.max_marks', 'exam.id as exam_id', 'exam.academic_year_id', 'exam.term_id', 'area.learning_area_id')->firstOrFail();
        $matches = $this->access->assignments($user)->filter(fn ($a) => $a->learning_area_id === $paper->learning_area_id && $a->academic_year_id === $paper->academic_year_id && $a->term_id === $paper->term_id);
        $assignment = $assignmentId ? $matches->firstWhere('id', $assignmentId) : ($matches->count() === 1 ? $matches->first() : null);
        abort_unless($assignment, 422, 'A unique active teacher assignment is required for this paper.');

        return [$paper, $assignment];
    }

    private function roster(User $user, $assignment)
    {
        return DB::table('learners')->where('school_id', $user->school_id)->where('grade_id', $assignment->grade_id)->where('stream_id', $assignment->stream_id)->where('active', true)->where('is_deleted', false);
    }
}
