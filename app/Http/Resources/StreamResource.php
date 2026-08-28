<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StreamResource extends JsonResource
{
    public function toArray(
        Request $request
    ): array {
        return [
            'id' => $this->id,
            'stream_name' => $this->stream_name,
            'active' => $this->active,

            'grade' => $this->whenLoaded(
                'grade'
            ),

            'created_at' => $this->created_at,
        ];
    }
}
