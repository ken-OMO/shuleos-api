<?php

namespace App\Http\Resources\StudentElection;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StudentElectionLeaderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $learner = $this->resource->relationLoaded('learner') ? $this->resource->getRelation('learner') : null;

        return [
            'id' => $this->resource->getAttribute('id'),
            'display_name' => $learner === null ? null : implode(' ', array_filter(
                [$learner->first_name, $learner->middle_name, $learner->last_name],
                static fn ($part) => $part !== null && $part !== ''
            )),
            'position' => new StudentElectionPositionResource($this->whenLoaded('position')),
            'starts_at' => $this->resource->getAttribute('starts_at'), 'ends_at' => $this->resource->getAttribute('ends_at'),
        ];
    }
}
