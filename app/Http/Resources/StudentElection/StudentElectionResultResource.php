<?php

namespace App\Http\Resources\StudentElection;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StudentElectionResultResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->getAttribute('id'), 'election_id' => $this->resource->getAttribute('election_id'),
            'election_position_id' => $this->resource->getAttribute('election_position_id'), 'candidate_id' => $this->resource->getAttribute('candidate_id'),
            'vote_count' => (int) $this->resource->getAttribute('vote_count'), 'rank' => (int) $this->resource->getAttribute('rank'), 'result_status' => $this->resource->getAttribute('result_status'),
            'counting_stage' => $this->when($this->resource->getAttribute('counting_stage') !== null, $this->resource->getAttribute('counting_stage')),
            'resolution_round_id' => $this->when($this->resource->getAttribute('counting_stage') !== null, $this->resource->getAttribute('resolution_round_id')),
        ];
    }
}
