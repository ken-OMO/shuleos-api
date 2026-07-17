<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CommunicationTargetResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'target_type' => $this->target_type, 'options' => is_string($this->options) ? json_decode($this->options, true) : $this->options];
    }
}
