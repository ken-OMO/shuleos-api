<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MarkEntryPermissionResource extends JsonResource
{
    public function toArray(
        Request $request
    ): array
    {
        return [

            'id' => $this->id,

            'role_name'
                => $this->role_name,

            'active'
                => $this->active,

            'exam'
                => $this->whenLoaded(

                    'exam'

                ),

            'created_at'
                => $this->created_at,

        ];
    }
}
