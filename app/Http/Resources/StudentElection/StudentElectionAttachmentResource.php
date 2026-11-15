<?php

namespace App\Http\Resources\StudentElection;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StudentElectionAttachmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->getAttribute('id'), 'election_id' => $this->resource->getAttribute('election_id'), 'position_id' => $this->resource->getAttribute('position_id'),
            'display_order' => $this->resource->getAttribute('display_order'), 'number_of_winners' => $this->resource->getAttribute('number_of_winners'),
            'candidate_eligibility_rules' => $this->resource->getAttribute('candidate_eligibility_rules'),
            'voter_eligibility_rules' => $this->resource->getAttribute('voter_eligibility_rules'), 'active' => (bool) $this->resource->getAttribute('active'),
        ];
    }
}
