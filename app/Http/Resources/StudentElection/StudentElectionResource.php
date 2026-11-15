<?php

namespace App\Http\Resources\StudentElection;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StudentElectionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->getAttribute('id'), 'title' => $this->resource->getAttribute('title'), 'description' => $this->resource->getAttribute('description'),
            'status' => $this->resource->getAttribute('status'), 'academic_year_id' => $this->resource->getAttribute('academic_year_id'), 'term_id' => $this->resource->getAttribute('term_id'),
            'nomination_opens_at' => $this->resource->getAttribute('nomination_opens_at'), 'nomination_closes_at' => $this->resource->getAttribute('nomination_closes_at'),
            'campaign_opens_at' => $this->resource->getAttribute('campaign_opens_at'), 'campaign_closes_at' => $this->resource->getAttribute('campaign_closes_at'),
            'voting_opens_at' => $this->resource->getAttribute('voting_opens_at'), 'voting_closes_at' => $this->resource->getAttribute('voting_closes_at'),
            'published_at' => $this->resource->getAttribute('published_at'),
            'positions' => $this->whenLoaded('electionPositions', fn () => $this->resource->getRelation('electionPositions')->map(fn ($ep) => [
                'id' => $ep->id, 'display_order' => $ep->display_order,
                'number_of_winners' => $ep->number_of_winners ?? $ep->position?->number_of_winners,
                'position' => $ep->position === null ? null : new StudentElectionPositionResource($ep->position),
            ])),
        ];
    }
}
