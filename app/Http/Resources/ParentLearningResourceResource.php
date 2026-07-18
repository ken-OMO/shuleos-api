<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

class ParentLearningResourceResource extends ParentPortalArrayResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'description' => $this->description,
            'resource_type' => $this->resource_type,
            'source_type' => $this->source_type,
            'external_url' => $this->source_type === 'external_link' ? $this->external_url : null,
            'learning_area' => $this->whenLoaded('learningArea', fn () => ['id' => $this->learningArea->id, 'name' => $this->learningArea->learning_area_name]),
            'published_at' => $this->published_at,
            'expires_at' => $this->expires_at ?? null,
            'download_available' => $this->source_type === 'uploaded_file',
        ];
    }
}
