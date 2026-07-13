<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\BaseApiController;
use App\Http\Resources\HomeworkAnalyticsResource;
use App\Models\HomeworkAssignment;
use App\Models\HomeworkSubmission;
use App\Services\Homework\HomeworkAnalyticsService;
use App\Services\Homework\HomeworkAssignmentService;
use App\Services\LeadershipPortal\LeadershipPortalAccessService;
use Illuminate\Http\Request;

class HomeworkLeadershipController extends BaseApiController
{
    public function __construct(private HomeworkAnalyticsService $analytics, private LeadershipPortalAccessService $access) {}

    private function query()
    {
        $s = $this->access->scope(auth()->user());

        return HomeworkAssignment::current()->where('school_id', auth()->user()->school_id)->when(! $s['whole_school'], fn ($q) => $q->whereIn('learning_area_id', $s['learning_area_ids']));
    }

    public function index()
    {
        return $this->success($this->query()->paginate(20));
    }

    public function show(string $assignment)
    {
        return $this->success($this->query()->whereKey($assignment)->with('learners', 'submissions.mark')->firstOrFail());
    }

    public function analytics()
    {
        return $this->success(new HomeworkAnalyticsResource($this->analytics->summary(auth()->user())));
    }

    public function completion(string $assignment)
    {
        $a = $this->query()->whereKey($assignment)->firstOrFail();

        return $this->success($a->learners()->selectRaw('submission_status,COUNT(*) total')->groupBy('submission_status')->get());
    }

    public function moderation(Request $request, string $submission, bool $complete = false)
    {
        $data = $request->validate(['comment' => 'required|string|max:4000', 'decision' => $complete ? 'required|in:confirm,return' : 'nullable']);
        $submissionModel = HomeworkSubmission::whereKey($submission)->where('school_id', auth()->user()->school_id)->with('mark')->firstOrFail();
        $assignment = $this->query()->whereKey($submissionModel->assignment_id)->firstOrFail();
        $mark = $submissionModel->mark()->lockForUpdate()->firstOrFail();
        if ($complete) {
            abort_unless($mark->status === 'moderation_required', 409);
            $mark->update(['status' => $data['decision'] === 'confirm' ? 'moderated' : 'returned']);
            $action = $data['decision'] === 'confirm' ? 'mark_moderated' : 'moderation_returned';
        } else {
            abort_unless(in_array($mark->status, ['marked', 'released'], true), 409);
            $mark->update(['status' => 'moderation_required']);
            $action = 'moderation_requested';
        }app(HomeworkAssignmentService::class)->audit($assignment, $action, auth()->id(), ['submission_id' => $submission, 'comment' => $data['comment']]);

        return $this->success($mark->fresh());
    }
}
