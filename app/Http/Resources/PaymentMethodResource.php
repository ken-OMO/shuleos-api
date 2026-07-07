<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PaymentMethodResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [

            'id' => $this->id,

            'school_id' => $this->school_id,

            'method_name' => $this->method_name,

            'is_online' => $this->is_online,

            'active' => $this->active,

            'created_at' => $this->created_at,

        ];
    }
}
