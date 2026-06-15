<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SchoolResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'school_name' => $this->school_name,
            'school_code' => $this->school_code,

            'email' => $this->email,
            'phone' => $this->phone,

            'county' => $this->county,
            'sub_county' => $this->sub_county,

            'school_type' => $this->school_type,
            'ownership' => $this->ownership,

            'registration_number' => $this->registration_number,
            'kra_pin' => $this->kra_pin,
            'website' => $this->website,

            'active' => $this->active,

            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
