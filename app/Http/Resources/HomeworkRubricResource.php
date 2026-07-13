<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class HomeworkRubricResource extends JsonResource
{
    public function toArray(Request $r): array
    {
        return ['id' => $this->id, 'title' => $this->title, 'description' => $this->description, 'total_points' => $this->total_points, 'criteria' => $this->whenLoaded('criteria')];
    }
}
