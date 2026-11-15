<?php

namespace App\Http\Resources\StudentElection;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StudentElectionCandidateReviewResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->getAttribute('id'), 'election_position_id' => $this->resource->getAttribute('election_position_id'),
            'status' => $this->resource->getAttribute('status'), 'review_comments' => $this->resource->getAttribute('review_comments'), 'reviewed_at' => $this->resource->getAttribute('reviewed_at'),
        ];
    }
}
