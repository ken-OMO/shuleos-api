<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RoomResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string,mixed>
     */
    public function toArray(Request $request): array
    {
        return [

            'id' => $this->id,

            'school_id' => $this->school_id,

            'room_type_id' => $this->room_type_id,

            'room_name' => $this->room_name,

            'room_code' => $this->room_code,

            'block_name' => $this->block_name,

            'floor_number' => $this->floor_number,

            'capacity' => $this->capacity,

            'active' => $this->active,

            'created_by' => $this->created_by,

            'created_at' => $this->created_at,

        ];
    }
}
