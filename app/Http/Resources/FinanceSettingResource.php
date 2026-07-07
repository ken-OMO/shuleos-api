<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FinanceSettingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [

            'id' => $this->id,

            'school_id' => $this->school_id,

            'currency' => $this->currency,

            'allow_partial_payments' => $this->allow_partial_payments,

            'allow_overpayments' => $this->allow_overpayments,

            'auto_generate_invoices' => $this->auto_generate_invoices,

            'require_fee_clearance_for_results' => $this->require_fee_clearance_for_results,

            'require_fee_clearance_for_report_cards' => $this->require_fee_clearance_for_report_cards,

            'require_fee_clearance_for_exams' => $this->require_fee_clearance_for_exams,

            'active' => $this->active,

            'created_at' => $this->created_at,

        ];
    }
}
