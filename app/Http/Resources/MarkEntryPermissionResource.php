<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MarkEntryPermissionResource extends JsonResource
{
    public function toArray(
        Request $request
    ): array {
        return [

            'id' => $this->id,

            'exam_id' => $this->exam_id,

            'role_name' => $this->role_name,

            'active' => $this->active,

            'opens_at' => $this->opens_at,

            'closes_at' => $this->closes_at,

            'is_open' => $this->isOpen(),

            'exam' => $this->whenLoaded(

                'exam'

            ),

            'created_at' => $this->created_at,

        ];
    }
}
