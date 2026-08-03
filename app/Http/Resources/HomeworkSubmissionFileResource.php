<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class HomeworkSubmissionFileResource extends JsonResource
{
    public function toArray(Request $r): array
    {
        return ['id' => $this->id, 'original_filename' => $this->original_filename, 'safe_download_filename' => $this->safe_download_filename, 'mime_type' => $this->mime_type, 'extension' => $this->extension, 'source_size' => $this->source_size, 'encrypted' => (bool) $this->encrypted, 'uploaded_at' => $this->uploaded_at];
    }
}
