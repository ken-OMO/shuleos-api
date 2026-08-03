<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FeeCategoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [

            'id' => $this->id,

            'category_name' => $this->category_name,

            'description' => $this->description,

            'is_system' => $this->is_system,

            'active' => $this->active,

            'created_at' => $this->created_at,

        ];
    }
}
