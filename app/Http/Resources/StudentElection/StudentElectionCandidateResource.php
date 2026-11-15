<?php

namespace App\Http\Resources\StudentElection;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StudentElectionCandidateResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $learner = $this->resource->relationLoaded('learner')
            ? $this->resource->getRelation('learner')
            : null;

        return [
            'id' => $this->resource->getAttribute('id'),
            'election_position_id' => $this->resource->getAttribute('election_position_id'),
            'display_name' => $learner === null ? null : implode(' ', array_filter(
                [$learner->first_name, $learner->middle_name, $learner->last_name],
                static fn ($part) => $part !== null && $part !== ''
            )),
            'manifesto' => $this->resource->getAttribute('manifesto'),
            'slogan' => $this->resource->getAttribute('slogan'),
            ...($this->resource->getAttribute('photo_version_id') !== null ? ['photo_version' => $this->resource->getAttribute('photo_version_id')] : []),
        ];
    }
}
