<?php

namespace App\Services\TeacherPortal;

use App\Models\MarkEntryBatch;
use App\Models\User;
use App\Services\Assessment\ExamResultLifecycleService;
use App\Services\Assessment\PaperResultValueService;
use Illuminate\Support\Facades\DB;

class MarkModerationService
{
    public function __construct(
        private TeacherHodScopeService $hod,
        private ExamResultLifecycleService $lifecycle
    ) {}

    public function query(User $user)
    {
        $scope = $this->hod->scope($user);
        $query = MarkEntryBatch::withoutGlobalScopes()->where('school_id', $user->school_id)->whereIn('status', ['submitted', 'under_moderation', 'changes_requested', 'approved', 'locked']);
        if (! $scope['whole_school']) {
            $query->whereIn('teacher_assignment_id', DB::table('teacher_assignments')->where('school_id', $user->school_id)->whereIn('learning_area_id', $scope['learning_area_ids'])->pluck('id'));
        }

        return $query;
    }

    public function decide(User $user, string $id, string $action, ?string $reason): MarkEntryBatch
    {
        abort_unless(in_array($action, ['approved', 'changes_requested', 'rejected', 'locked'], true), 422);
        if (in_array($action, ['changes_requested', 'rejected'], true)) {
            abort_if(blank($reason), 422, 'A moderation reason is required.');
        }

        return DB::transaction(function () use ($user, $id, $action, $reason) {
            $batch = $this->query($user)->whereKey($id)->lockForUpdate()->firstOrFail();
            abort_if($batch->entered_by === $user->id, 403, 'Self-moderation is not allowed.');
            $allowed = ['submitted' => ['approved', 'changes_requested', 'rejected'], 'approved' => ['locked'], 'changes_requested' => [], 'locked' => []];
            abort_unless(in_array($action, $allowed[$batch->status] ?? [], true), 409);
            $batch->update(['status' => $action, 'review_reason' => $reason, 'moderated_by' => $user->id, 'moderated_at' => now(), 'locked_at' => $action === 'locked' ? now() : $batch->locked_at, 'version' => $batch->version + 1]);

            return $batch->fresh('items');
        });
    }

    public function decideCorrection(User $user, string $requestId, string $decision, ?string $reason)
    {
        abort_unless(in_array($decision, ['approved', 'rejected'], true), 422);

        return DB::transaction(function () use ($user, $requestId, $decision, $reason) {
            $candidateRequest = DB::table('mark_correction_requests')
                ->where('school_id', $user->school_id)
                ->where('status', 'pending')
                ->where('id', $requestId)
                ->firstOrFail();

            $candidateBatch = $this->query($user)
                ->whereKey($candidateRequest->batch_id)
                ->firstOrFail();

            $exam = $this->lifecycle->lockExam($user->school_id, $candidateBatch->exam_id);

            $request = DB::table('mark_correction_requests')->where('school_id', $user->school_id)->where('status', 'pending')->where('id', $requestId)->lockForUpdate()->firstOrFail();
            $batch = $this->query($user)->whereKey($request->batch_id)->lockForUpdate()->firstOrFail();
            abort_if($request->requested_by === $user->id, 403);
            abort_unless($batch->exam_id === $exam->id, 409, 'The correction batch exam changed.');

            if ($decision === 'approved') {
                $this->lifecycle->assertMutable($exam);
                abort_unless($exam->status === 'published', 409, 'Correction approval requires a published exam.');
            }
            abort_if($decision === 'approved' && $batch->submitted_at && $batch->submitted_at->lt(now()->subHours(config('teacher_portal_phase_two.correction_window_hours', 48))), 409, 'The mark correction window has closed.');
            DB::table('mark_correction_requests')->where('id', $request->id)->update(['status' => $decision, 'decided_by' => $user->id, 'decided_at' => now(), 'decision_reason' => $reason, 'updated_at' => now()]);
            if ($decision === 'approved') {
                $batch->update(['status' => 'reopened', 'version' => $batch->version + 1]);
                if ($request->batch_item_id
                    && ($request->proposed_marks !== null || $request->proposed_is_absent !== null)) {
                    $item = DB::table('mark_entry_batch_items')
                        ->where('id', $request->batch_item_id)
                        ->where('batch_id', $batch->id)
                        ->where('school_id', $user->school_id)
                        ->lockForUpdate()
                        ->first();

                    abort_unless($item, 409, 'The correction item is unavailable.');

                    $paper = DB::table('exam_papers as paper')
                        ->join('exam_learning_areas as area', 'area.id', '=', 'paper.exam_learning_area_id')
                        ->where('paper.id', $batch->exam_paper_id)
                        ->where('paper.is_deleted', false)
                        ->where('area.is_deleted', false)
                        ->where('area.exam_id', $exam->id)
                        ->select('paper.max_marks')
                        ->first();

                    abort_unless($paper, 409, 'The correction paper is unavailable.');

                    $value = app(PaperResultValueService::class)->normalize([
                        'marks' => $request->proposed_marks,
                        'is_absent' => $request->proposed_is_absent ?? false,
                    ], (float) $paper->max_marks);

                    DB::table('mark_entry_batch_items')
                        ->where('id', $item->id)
                        ->update($value + [
                            'version' => DB::raw('version + 1'),
                            'updated_at' => now(),
                        ]);
                }
            }

            return DB::table('mark_correction_requests')->where('id', $request->id)->first();
        });
    }
}
