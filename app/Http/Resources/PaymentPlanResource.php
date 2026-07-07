<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PaymentPlanResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [

            'id' => $this->id,

            'school_id' => $this->school_id,

            'plan_name' => $this->plan_name,

            'description' => $this->description,

            'number_of_installments' => $this->number_of_installments,

            'active' => $this->active,

            'created_at' => $this->created_at,

        ];
    }
}
