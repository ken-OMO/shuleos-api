<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LearningResourceResource extends JsonResource
{
    public function toArray(Request $r): array
    {
        return ['id' => $this->id, 'title' => $this->title, 'description' => $this->description, 'resource_type' => $this->resource_type, 'source_type' => $this->source_type, 'external_url' => $this->source_type === 'external_link' ? $this->external_url : null, 'visibility' => $this->visibility, 'publication_status' => $this->publication_status, 'strand' => $this->strand, 'sub_strand' => $this->sub_strand, 'category' => $this->whenLoaded('category'), 'learning_area' => $this->whenLoaded('learningArea'), 'grade' => $this->whenLoaded('grade'), 'stream' => $this->whenLoaded('stream'), 'current_version' => new LearningResourceVersionResource($this->whenLoaded('currentVersion')), 'created_at' => $this->created_at];
    }
}
