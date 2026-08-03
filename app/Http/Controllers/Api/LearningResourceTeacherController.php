<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\BaseApiController;
use App\Http\Resources\LearningResourceResource;
use App\Http\Resources\LearningResourceVersionResource;
use App\Services\LearningResource\LearningResourceDeliveryService;
use App\Services\LearningResource\LearningResourceService;
use App\Services\LearningResource\LearningResourceUploadService;
use Illuminate\Http\Request;

class LearningResourceTeacherController extends BaseApiController
{
    public function __construct(private readonly LearningResourceService $service, private readonly LearningResourceUploadService $upload, private readonly LearningResourceDeliveryService $delivery) {}

    public function index(Request $request)
    {
        $items = $this->service->teacherQuery(auth()->user(), $request->only(['learning_area_id', 'grade_id', 'stream_id', 'resource_type', 'category_id', 'publication_status', 'strand', 'sub_strand', 'academic_year_id', 'term_id', 'keyword']))->with($this->service->relations())->paginate(20);

        return $this->success(LearningResourceResource::collection($items));
    }

    public function show(string $resource)
    {
        $item = $this->service->teacherQuery(auth()->user())->whereKey($resource)->with($this->service->relations())->firstOrFail();

        return $this->success(new LearningResourceResource($item));
    }

    public function create(Request $request)
    {
        return $this->created(new LearningResourceResource($this->service->external($this->school($request), auth()->id(), $this->data($request))));
    }

    public function upload(Request $request)
    {
        $data = $this->data($request, true);
        $file = $data['file'];
        unset($data['file']);

        return $this->created(new LearningResourceResource($this->upload->create($this->school($request), auth()->id(), $data, $file)));
    }

    public function update(Request $request, string $resource)
    {
        return $this->success(new LearningResourceResource($this->service->updateOwn(auth()->user(), $resource, $this->data($request, false, true))));
    }

    public function submit(Request $request, string $resource)
    {
        return $this->success(new LearningResourceResource($this->service->transition($this->school($request), $resource, 'pending_review', auth()->user())));
    }

    public function archive(Request $request, string $resource)
    {
        return $this->success(new LearningResourceResource($this->service->transition($this->school($request), $resource, 'archived', auth()->user())));
    }

    public function download(string $resource)
    {
        $item = $this->service->teacherQuery(auth()->user())->whereKey($resource)->firstOrFail();

        return $this->delivery->download(auth()->user(), $item, historical: true);
    }

    public function versions(string $resource)
    {
        $item = $this->service->teacherQuery(auth()->user())->whereKey($resource)->firstOrFail();

        return $this->success(LearningResourceVersionResource::collection($item->versions()->with('creator')->get()->each->setAttribute('current_version_number', $item->current_version_number)));
    }

    public function downloadVersion(string $resource, string $version)
    {
        $item = $this->service->teacherQuery(auth()->user())->whereKey($resource)->firstOrFail();
        $historical = $item->versions()->whereKey($version)->firstOrFail();

        return $this->delivery->download(auth()->user(), $item, $historical, historical: true);
    }

    public function uploadVersion(Request $request, string $resource)
    {
        $data = $request->validate(['file' => 'required|file', 'change_notes' => 'nullable|string|max:2000']);

        return $this->created(new LearningResourceVersionResource($this->upload->replace(auth()->user(), $resource, $data['file'], $data['change_notes'] ?? null)));
    }

    public function linkVersion(Request $request, string $resource)
    {
        $data = $request->validate(['external_url' => 'required|string|max:2048', 'change_notes' => 'nullable|string|max:2000']);

        return $this->created(new LearningResourceVersionResource($this->service->addLinkVersion(auth()->user(), $resource, $data['external_url'], $data['change_notes'] ?? null)));
    }

    public function restore(Request $request, string $resource, string $version)
    {
        $data = $request->validate(['change_notes' => 'nullable|string|max:2000']);

        return $this->created(new LearningResourceVersionResource($this->service->restore(auth()->user(), $resource, $version, $data['change_notes'] ?? null)));
    }

    private function data(Request $request, bool $file = false, bool $update = false): array
    {
        $required = $update ? 'sometimes' : 'required';

        return $request->validate(['category_id' => 'nullable|uuid', 'learning_area_id' => "$required|uuid", 'grade_id' => "$required|uuid", 'stream_id' => 'nullable|uuid', 'academic_year_id' => 'nullable|uuid', 'term_id' => 'nullable|uuid', 'scheme_id' => 'nullable|uuid', 'scheme_lesson_id' => 'nullable|uuid', 'title' => "$required|string|max:255", 'description' => 'nullable|string|max:10000', 'resource_type' => "$required|string|max:100", 'external_url' => $file ? 'nullable' : ($update ? 'prohibited' : 'required|string|max:2048'), 'visibility' => "$required|in:private,assigned_class,grade,school,parents", 'file' => $file ? 'required|file' : 'nullable', 'change_notes' => 'nullable|string|max:2000']);
    }

    private function school(Request $request): string
    {
        return (string) $request->attributes->get('tenant_school_id');
    }
}
