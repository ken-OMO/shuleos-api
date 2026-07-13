<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LearningResourceVersionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'version_number' => $this->version_number, 'is_current' => (int) $this->version_number === (int) ($this->current_version_number ?? $this->resource?->current_version_number), 'source_type' => $this->storage_id ? 'uploaded_file' : 'external_link', 'original_filename' => $this->original_filename, 'safe_download_filename' => $this->safe_download_filename, 'mime_type' => $this->mime_type, 'extension' => $this->extension, 'source_size' => $this->source_size, 'encrypted' => (bool) $this->encrypted, 'external_url' => $this->external_url, 'change_notes' => $this->change_notes, 'author' => $this->whenLoaded('creator', fn () => ['id' => $this->creator->id, 'name' => trim($this->creator->first_name.' '.$this->creator->last_name)]), 'created_at' => $this->created_at];
    }
}
