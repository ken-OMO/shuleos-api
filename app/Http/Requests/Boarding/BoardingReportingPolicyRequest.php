<?php

declare(strict_types=1);

namespace App\Http\Requests\Boarding;

class BoardingReportingPolicyRequest extends BoardingDailyReportRequest
{
    public function rules(): array
    {
        $rules = array_merge(parent::rules(), [
            'timezone' => ['prohibited'],
            'policy_id' => ['prohibited'],
            'due_at' => ['prohibited'],
            'reporting_date' => ['prohibited'],
            'date' => ['prohibited'],
            'from' => ['prohibited'],
            'to' => ['prohibited'],
            'revision' => ['prohibited'],
        ]);

        if ($this->route()->getActionMethod() === 'configure') {
            $rules = array_merge($rules, [
                'expected_revision' => ['required', 'integer', 'min:0'],
                'enabled' => ['required', 'boolean'],
                'effective_from' => ['required', 'date_format:Y-m-d'],
                'deadline_time' => ['required', 'date_format:H:i'],
                'deadline_day_offset' => ['required', 'integer', 'between:0,1'],
                'grace_minutes' => ['required', 'integer', 'between:0,1440'],
                'weekday_mask' => ['required', 'integer', 'between:1,127'],
            ]);
        }

        return $rules;
    }
}
