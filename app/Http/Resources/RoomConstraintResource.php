<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RoomConstraintResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [

            'id' => $this->id,

            'school_id' => $this->school_id,

            'room_id' => $this->room_id,

            'learning_area_id' => $this->learning_area_id,

            'constraint_type' => $this->constraint_type,

            'constraint_value' => $this->constraint_value,

            'active' => $this->active,

            'created_at' => $this->created_at,

        ];
    }
}
