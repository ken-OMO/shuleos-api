<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LearningResourceVersionResource extends JsonResource
{
    public function toArray(Request $r): array
    {
        return ['id' => $this->id, 'version_number' => $this->version_number, 'original_filename' => $this->original_filename, 'safe_download_filename' => $this->safe_download_filename, 'mime_type' => $this->mime_type, 'extension' => $this->extension, 'source_size' => $this->source_size, 'stored_size' => $this->stored_size, 'encrypted' => $this->encrypted, 'external_url' => $this->external_url, 'change_notes' => $this->change_notes, 'created_at' => $this->created_at];
    }
}
