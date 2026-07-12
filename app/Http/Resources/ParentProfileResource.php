<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ParentProfileResource extends JsonResource
{
    public function toArray(Request $r): array
    {
        return ['id' => $this->id, 'first_name' => $this->first_name, 'last_name' => $this->last_name, 'phone' => $this->phone, 'email' => $this->email];
    }
}
