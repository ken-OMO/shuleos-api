<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FinancePaymentPlanResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'learner_id' => $this->learner_id, 'plan_name' => $this->plan_name, 'description' => $this->description, 'academic_year_id' => $this->academic_year_id, 'term_id' => $this->term_id, 'total_planned_amount' => $this->total_planned_amount, 'number_of_installments' => $this->number_of_installments, 'status' => $this->status, 'installments' => $this->installments ?? [], 'invoice_numbers' => $this->invoice_numbers ?? []];
    }
}
