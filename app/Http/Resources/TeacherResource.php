<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TeacherResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,

            'tsc_no' => $this->tsc_no,
            'staff_no' => $this->staff_no,

            'gender' => $this->gender,
            'designation' => $this->designation,
            'employment_type' => $this->employment_type,

            'phone' => $this->phone,
            'email' => $this->email,

            'national_id' => $this->national_id,
            'date_joined' => $this->date_joined,

            'active' => $this->active,

            'user' => new UserResource(
                $this->whenLoaded('user')
            ),

            'school' => new SchoolResource(
                $this->whenLoaded('school')
            ),

            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
