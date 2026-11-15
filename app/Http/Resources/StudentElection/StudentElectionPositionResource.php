<?php

namespace App\Http\Resources\StudentElection;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StudentElectionPositionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->getAttribute('id'), 'name' => $this->resource->getAttribute('name'), 'code' => $this->resource->getAttribute('code'),
            'description' => $this->resource->getAttribute('description'), 'scope' => $this->resource->getAttribute('scope'),
            'grade_id' => $this->resource->getAttribute('grade_id'), 'stream_id' => $this->resource->getAttribute('stream_id'),
            'number_of_winners' => $this->resource->getAttribute('number_of_winners'), 'active' => (bool) $this->resource->getAttribute('active'),
        ];
    }
}
