<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TeacherProfileResource extends JsonResource
{
    public function toArray(Request $r): array
    {
        return ['id' => $this->id, 'staff_no' => $this->staff_no, 'tsc_no' => $this->tsc_no, 'designation' => $this->designation, 'employment_type' => $this->employment_type, 'phone' => $this->phone, 'email' => $this->email, 'school' => $this->whenLoaded('school'), 'assignments' => $this->whenLoaded('assignments')];
    }
}
