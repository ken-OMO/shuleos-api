<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\BaseApiController;
use App\Http\Resources\HomeworkAnalyticsResource;
use App\Models\HomeworkAssignment;
use App\Services\Homework\HomeworkAnalyticsService;
use App\Services\LeadershipPortal\LeadershipPortalAccessService;

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
}
