<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\BaseApiController;
use App\Http\Resources\LearningResourceResource;
use App\Services\LearningResource\LearningResourceDeliveryService;
use App\Services\LearningResource\LearningResourceService;
use App\Services\ParentPortal\ParentPortalAccessService;

class LearningResourceParentController extends BaseApiController
{
    public function __construct(private readonly ParentPortalAccessService $access, private readonly LearningResourceService $resources, private readonly LearningResourceDeliveryService $delivery) {}

    public function index(string $learner)
    {
        $child = $this->access->requireLinkedLearner(auth()->user(), $learner);
        $items = $this->resources->publishedForLearner($child->school_id, $child->grade_id, $child->stream_id, ['parents'])->with($this->resources->relations())->paginate(20);

        return $this->success(LearningResourceResource::collection($items));
    }

    public function show(string $learner, string $resource)
    {
        $child = $this->access->requireLinkedLearner(auth()->user(), $learner);
        $item = $this->resources->publishedForLearner($child->school_id, $child->grade_id, $child->stream_id, ['parents'])->whereKey($resource)->with($this->resources->relations())->firstOrFail();
        $this->resources->logAccess(auth()->user(), $item, $item->currentVersion, 'view');

        return $this->success(new LearningResourceResource($item));
    }

    public function download(string $learner, string $resource)
    {
        $child = $this->access->requireLinkedLearner(auth()->user(), $learner);
        $item = $this->resources->publishedForLearner($child->school_id, $child->grade_id, $child->stream_id, ['parents'])->whereKey($resource)->firstOrFail();

        return $this->delivery->download(auth()->user(), $item);
    }

    public function open(string $learner, string $resource)
    {
        $child = $this->access->requireLinkedLearner(auth()->user(), $learner);
        $item = $this->resources->publishedForLearner($child->school_id, $child->grade_id, $child->stream_id, ['parents'])->whereKey($resource)->with('currentVersion')->firstOrFail();
        abort_unless($item->source_type === 'external_link' && $item->currentVersion?->external_url, 404);
        $this->resources->logAccess(auth()->user(), $item, $item->currentVersion, 'open_external_link');

        return $this->success(['url' => $item->currentVersion->external_url]);
    }
}
