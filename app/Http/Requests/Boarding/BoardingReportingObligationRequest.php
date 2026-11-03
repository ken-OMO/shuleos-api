<?php

declare(strict_types=1);

namespace App\Http\Requests\Boarding;

class BoardingReportingObligationRequest extends BoardingDailyReportRequest
{
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d'],
            'hostel_id' => ['sometimes', 'uuid'],
            'page' => ['sometimes', 'integer', 'between:1,100000'],
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
        ]);
    }
}
