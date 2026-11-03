<?php

declare(strict_types=1);

namespace App\Http\Requests\Boarding;

class BoardingOccurrenceCategoryRequest extends BoardingDailyReportRequest
{
    public function rules(): array
    {
        $rules = array_merge(parent::rules(), [
            'category_name' => ['required', 'string', 'max:100'],
            'active' => ['required', 'boolean'],
            'created_at' => ['prohibited'],
        ]);

        if ($this->route()->getActionMethod() === 'update') {
            $rules['expected_version'] = ['required', 'string', 'regex:/^[a-f0-9]{64}$/'];
        }

        return $rules;
    }
}
