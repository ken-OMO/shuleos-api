<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TimetableConstraintResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [

            'id' => $this->id,

            'school_id' => $this->school_id,

            'constraint_name' => $this->constraint_name,

            'constraint_category' => $this->constraint_category,

            'priority' => $this->priority,

            'active' => $this->active,

            'description' => $this->description,

            'created_at' => $this->created_at,

        ];
    }
}
