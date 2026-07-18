<?php

namespace App\Services\TeacherPortal;

use App\Models\MarkEntryBatch;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class MarkModerationService
{
    public function __construct(private TeacherHodScopeService $hod) {}

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
            $request = DB::table('mark_correction_requests')->where('school_id', $user->school_id)->where('status', 'pending')->where('id', $requestId)->lockForUpdate()->firstOrFail();
            $batch = $this->query($user)->whereKey($request->batch_id)->lockForUpdate()->firstOrFail();
            abort_if($request->requested_by === $user->id, 403);
            abort_if($decision === 'approved' && $batch->submitted_at && $batch->submitted_at->lt(now()->subHours(config('teacher_portal_phase_two.correction_window_hours', 48))), 409, 'The mark correction window has closed.');
            DB::table('mark_correction_requests')->where('id', $request->id)->update(['status' => $decision, 'decided_by' => $user->id, 'decided_at' => now(), 'decision_reason' => $reason, 'updated_at' => now()]);
            if ($decision === 'approved') {
                $batch->update(['status' => 'reopened', 'version' => $batch->version + 1]);
                if ($request->batch_item_id && $request->proposed_marks !== null) {
                    DB::table('mark_entry_batch_items')->where('id', $request->batch_item_id)->update(['marks' => $request->proposed_marks, 'version' => DB::raw('version + 1'), 'updated_at' => now()]);
                }
            }

            return DB::table('mark_correction_requests')->where('id', $request->id)->first();
        });
    }
}
