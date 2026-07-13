<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\BaseApiController;
use App\Http\Resources\HomeworkSubmissionResource;
use App\Services\Homework\HomeworkLearnerService;
use App\Services\Homework\HomeworkSubmissionFileService;
use Illuminate\Http\Request;

class HomeworkLearnerController extends BaseApiController
{
    public function __construct(private HomeworkLearnerService $s, private HomeworkSubmissionFileService $files) {}

    public function index()
    {
        return $this->success($this->s->records(auth()->user()));
    }

    public function show(string $assignment)
    {
        return $this->success($this->s->record(auth()->user(), $assignment)->load('assignment.resources', 'submissions.files', 'submissions.mark'));
    }

    public function view(string $assignment)
    {
        $r = $this->s->record(auth()->user(), $assignment);
        $r->update(['first_viewed_at' => $r->first_viewed_at ?? now(), 'last_viewed_at' => now(), 'submission_status' => $r->submission_status === 'not_started' ? 'in_progress' : $r->submission_status]);

        return $this->success(null);
    }

    public function draft(Request $r, string $assignment)
    {
        $d = $r->validate(['text_response' => 'nullable|string', 'external_url' => 'nullable|string|max:2048', 'learner_comment' => 'nullable|string|max:2000']);
        if (! empty($d['external_url'])) {
            $d['external_url'] = $this->s->safeUrl($d['external_url']);
        }

        return $this->success(new HomeworkSubmissionResource($this->s->draft(auth()->user(), $assignment, $d)));
    }

    public function upload(Request $r, string $assignment)
    {
        $d = $r->validate(['file' => 'required|file']);

        return $this->created($this->files->upload(auth()->user(), $assignment, $d['file']));
    }

    public function submit(string $assignment)
    {
        return $this->success(new HomeworkSubmissionResource($this->s->submit(auth()->user(), $assignment)));
    }

    public function feedback(string $assignment)
    {
        $record = $this->s->record(auth()->user(), $assignment);
        $mark = $record->submissions()->with('mark')->get()->pluck('mark')->filter(fn ($m) => $m?->status === 'released')->last();

        return $this->success($mark);
    }
}
