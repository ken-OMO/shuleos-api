<?php

declare(strict_types=1);

namespace App\Http\Requests\Boarding;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BoardingDailyReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function validationData(): array
    {
        $data = parent::validationData();

        // Tenant middleware injects authoritative school_id into the input.
        // Its separate attribute preserves whether the client supplied it.
        if ($this->attributes->get('client_supplied_school_id') === false) {
            unset($data['school_id']);
        }

        return $data;
    }

    public function rules(): array
    {
        $rules = [
            'school_id' => ['prohibited'],
            'hostel_id' => ['prohibited'],
            'assignment_id' => ['prohibited'],
            'created_by' => ['prohibited'],
            'updated_by' => ['prohibited'],
            'submitted_by' => ['prohibited'],
            'reviewed_by' => ['prohibited'],
            'status' => ['prohibited'],
            'snapshot' => ['prohibited'],
            'revision_number' => ['prohibited'],
            'submitted_at' => ['prohibited'],
            'reviewed_at' => ['prohibited'],
        ];

        return array_merge($rules, match ($this->route()->getActionMethod()) {
            'updateDraft' => [
                'version' => ['required', 'integer', 'min:1'],
                'summary' => ['present', 'string', 'max:10000'],
                'no_occurrences' => ['required', 'boolean'],
            ],
            'submit' => [
                'version' => ['required', 'integer', 'min:1'],
            ],
            'review' => [
                'decision' => ['required', Rule::in(['reviewed', 'changes_requested'])],
                'comment' => ['nullable', 'string', 'max:4000'],
            ],
            default => [],
        });
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            if ($this->attributes->get('client_supplied_school_id') === true) {
                $validator->errors()->add(
                    'school_id',
                    'The school_id field is prohibited.'
                );
            }
        });
    }
}
