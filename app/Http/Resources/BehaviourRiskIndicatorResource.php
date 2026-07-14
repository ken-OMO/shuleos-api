<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BehaviourRiskIndicatorResource extends JsonResource
{
    public function toArray(Request $r): array
    {
        return $this->resource;
    }
}
