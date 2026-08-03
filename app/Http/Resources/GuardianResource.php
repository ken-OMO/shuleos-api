<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class GuardianResource extends JsonResource
{
    /**
     * Transform resource.
     */
    public function toArray(
        Request $request
    ): array {
        return [

            'id' => $this->id,

            'first_name' => $this->first_name,

            'last_name' => $this->last_name,

            'phone' => $this->phone,

            'email' => $this->email,

            'relationship' => $this->relationship,

            'active' => $this->active,

            'school' => $this->whenLoaded(

                'school'

            ),

            'user' => $this->whenLoaded(

                'user'

            ),

            'learners' => $this->whenLoaded(

                'learners'

            ),

            'created_at' => $this->created_at,

        ];
    }
}
