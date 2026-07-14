<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BehaviourRecognitionResource extends JsonResource
{
    public function toArray(Request $r): array
    {
        return ['id' => $this->id, 'recognition_type' => $this->recognition_type, 'title' => $this->title, 'description' => $this->description, 'points' => $this->points, 'awarded_at' => $this->awarded_at, 'status' => $this->status];
    }
}
