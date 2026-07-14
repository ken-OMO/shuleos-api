<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DisciplineCategoryResource extends JsonResource
{
    public function toArray(Request $r): array
    {
        return ['id' => $this->id, 'name' => $this->category_name, 'description' => $this->description, 'category_type' => $this->category_type, 'default_severity' => $this->default_severity ?? $this->severity_level, 'active' => (bool) $this->active];
    }
}
