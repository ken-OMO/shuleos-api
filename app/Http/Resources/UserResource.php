<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'username' => $this->username,

            'first_name' => $this->first_name,
            'middle_name' => $this->middle_name,
            'last_name' => $this->last_name,

            'email' => $this->email,
            'phone' => $this->phone,

            'active' => $this->active,
            'first_login' => $this->first_login,

            'school_id' => $this->school_id,
            'role_id' => $this->role_id,

          'school' => new SchoolResource(
    $this->whenLoaded('school')
),

'role' => new RoleResource(
    $this->whenLoaded('role')
),

            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
