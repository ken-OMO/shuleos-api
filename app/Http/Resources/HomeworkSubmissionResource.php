<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class HomeworkSubmissionResource extends JsonResource
{
    public function toArray(Request $r): array
    {
        return ['id' => $this->id, 'attempt_number' => $this->attempt_number, 'text_response' => $this->text_response, 'external_url' => $this->external_url, 'submitted_at' => $this->submitted_at, 'is_late' => (bool) $this->is_late, 'lateness_minutes' => $this->lateness_minutes, 'submission_status' => $this->submission_status, 'learner_comment' => $this->learner_comment, 'files' => HomeworkSubmissionFileResource::collection($this->whenLoaded('files')), 'mark' => new HomeworkMarkResource($this->whenLoaded('mark'))];
    }
}
