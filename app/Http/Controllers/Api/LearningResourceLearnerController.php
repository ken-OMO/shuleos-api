<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\BaseApiController;
use App\Http\Resources\LearningResourceResource;
use App\Models\LearningResource;
use App\Services\LearnerPortal\LearnerPortalAccessService;
use App\Services\LearningResource\LearningResourceDeliveryService;
use App\Services\LearningResource\LearningResourceService;
use Illuminate\Http\Request;

class LearningResourceLearnerController extends BaseApiController
{
    public function __construct(private readonly LearningResourceService $s, private readonly LearnerPortalAccessService $a, private readonly LearningResourceDeliveryService $d) {}

    public function index()
    {
        return $this->success(LearningResourceResource::collection($this->s->learnerResources(auth()->user(), $this->a)));
    }

    public function show(string $resource)
    {
        $l = $this->a->learner(auth()->user());
        $r = LearningResource::current()->whereKey($resource)->where('school_id', $l->school_id)->where('grade_id', $l->grade_id)->where(fn ($q) => $q->whereNull('stream_id')->orWhere('stream_id', $l->stream_id))->where('publication_status', 'published')->with('currentVersion', 'category', 'learningArea', 'grade', 'stream')->firstOrFail();

        return $this->success(new LearningResourceResource($r));
    }

    public function download(string $resource)
    {
        $this->show($resource);

        return $this->d->download(auth()->user(), $resource);
    }

    public function bookmark(string $resource)
    {
        $this->s->bookmark(auth()->user(), $resource);

        return $this->success(null);
    }

    public function unbookmark(string $resource)
    {
        $this->s->bookmark(auth()->user(), $resource, false);

        return $this->success(null);
    }

    public function rate(Request $r, string $resource)
    {
        $v = $r->validate(['rating' => 'required|integer|min:1|max:5', 'review' => 'nullable|string']);
        $this->s->rate(auth()->user(), $resource, $v['rating'], $v['review'] ?? null);

        return $this->success(null);
    }
}
