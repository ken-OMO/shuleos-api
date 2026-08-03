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

        $this->s->logAccess(auth()->user(), $r, $r->currentVersion, 'view', $l->id);

        return $this->success(new LearningResourceResource($r));
    }

    public function download(string $resource)
    {
        $l = $this->a->learner(auth()->user());
        $item = $this->s->publishedForLearner($l->school_id, $l->grade_id, $l->stream_id, ['assigned_class', 'grade', 'school'])->whereKey($resource)->firstOrFail();

        return $this->d->download(auth()->user(), $item, learnerId: $l->id);
    }

    public function bookmark(string $resource)
    {
        $l = $this->a->learner(auth()->user());
        $item = $this->s->publishedForLearner($l->school_id, $l->grade_id, $l->stream_id, ['assigned_class', 'grade', 'school'])->whereKey($resource)->firstOrFail();
        $this->s->bookmark(auth()->user(), $item);

        return $this->success(null);
    }

    public function unbookmark(string $resource)
    {
        $l = $this->a->learner(auth()->user());
        $item = $this->s->publishedForLearner($l->school_id, $l->grade_id, $l->stream_id, ['assigned_class', 'grade', 'school'])->whereKey($resource)->firstOrFail();
        $this->s->bookmark(auth()->user(), $item, false);

        return $this->success(null);
    }

    public function rate(Request $r, string $resource)
    {
        $v = $r->validate(['rating' => 'required|integer|min:1|max:5', 'review' => 'nullable|string']);
        $l = $this->a->learner(auth()->user());
        $item = $this->s->publishedForLearner($l->school_id, $l->grade_id, $l->stream_id, ['assigned_class', 'grade', 'school'])->whereKey($resource)->firstOrFail();
        $this->s->rate(auth()->user(), $item, $v['rating'], $v['review'] ?? null);

        return $this->success(null);
    }

    public function open(string $resource)
    {
        $learner = $this->a->learner(auth()->user());
        $item = $this->s->publishedForLearner($learner->school_id, $learner->grade_id, $learner->stream_id, ['assigned_class', 'grade', 'school'])->whereKey($resource)->with('currentVersion')->firstOrFail();
        abort_unless($item->source_type === 'external_link' && $item->currentVersion?->external_url, 404);
        $this->s->logAccess(auth()->user(), $item, $item->currentVersion, 'open_external_link', $learner->id);

        return $this->success(['url' => $item->currentVersion->external_url]);
    }
}
