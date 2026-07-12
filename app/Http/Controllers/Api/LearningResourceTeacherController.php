<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\BaseApiController;
use App\Http\Resources\LearningResourceResource;
use App\Services\LearningResource\LearningResourceService;
use App\Services\LearningResource\LearningResourceUploadService;
use Illuminate\Http\Request;

class LearningResourceTeacherController extends BaseApiController
{
    public function __construct(private readonly LearningResourceService $s, private readonly LearningResourceUploadService $upload) {}

    private function data(Request $r, bool $file = false): array
    {
        return $r->validate(['category_id' => 'nullable|uuid', 'learning_area_id' => 'required|uuid', 'grade_id' => 'required|uuid', 'stream_id' => 'nullable|uuid', 'academic_year_id' => 'nullable|uuid', 'term_id' => 'nullable|uuid', 'scheme_id' => 'nullable|uuid', 'scheme_lesson_id' => 'nullable|uuid', 'title' => 'required|string|max:255', 'description' => 'nullable|string', 'resource_type' => 'required|string', 'external_url' => $file ? 'nullable' : 'required|url', 'visibility' => 'required|in:private,assigned_class,grade,school,parents', 'file' => $file ? 'required|file' : 'nullable', 'change_notes' => 'nullable|string']);
    }

    private function school(Request $r): string
    {
        return (string) $r->attributes->get('tenant_school_id');
    }

    public function create(Request $r)
    {
        return $this->created(new LearningResourceResource($this->s->external($this->school($r), auth()->id(), $this->data($r))));
    }

    public function upload(Request $r)
    {
        $d = $this->data($r, true);
        $f = $d['file'];
        unset($d['file']);

        return $this->created(new LearningResourceResource($this->upload->create($this->school($r), auth()->id(), $d, $f)));
    }

    public function submit(Request $r, string $resource)
    {
        return $this->success(new LearningResourceResource($this->s->transition($this->school($r), $resource, 'pending_review', auth()->id())));
    }
}
