<?php

declare(strict_types=1);

namespace App\Http\Requests\Boarding;

class BoardingReportOccurrenceRequest extends BoardingDailyReportRequest
{
    public function validationData(): array
    {
        $data = parent::validationData();

        // Laravel converts empty input strings to null.
        // These optional narrative fields are stored as explicit empty strings.
        foreach (['actions_taken', 'follow_up_notes'] as $field) {
            if (array_key_exists($field, $data) && $data[$field] === null) {
                $data[$field] = '';
            }
        }

        return $data;
    }

    public function rules(): array
    {
        $method = $this->route()->getActionMethod();
        $rules = array_merge(parent::rules(), [
            'version' => ['required', 'integer', 'min:1'],
            'learner_id' => ['prohibited'],
            'learner_ids' => ['prohibited'],
            'recorded_by' => ['prohibited'],
            'recorded_at' => ['prohibited'],
            'event_id' => ['prohibited'],
            'previous_id' => ['prohibited'],
            'event_state' => ['prohibited'],
        ]);

        if ($method !== 'store') {
            $rules['event_version'] = ['required', 'integer', 'min:1'];
            $rules['reason'] = ['required', 'string', 'max:500'];
        }

        if ($method !== 'void') {
            $rules = array_merge($rules, [
                'category_id' => ['required', 'uuid'],
                'occurred_at' => ['required', 'string', 'date'],
                'description' => ['required', 'string', 'max:5000'],
                'actions_taken' => ['present', 'string', 'max:5000'],
                'follow_up_required' => ['required', 'boolean'],
                'follow_up_notes' => ['present', 'string', 'max:2000'],
            ]);
        }

        return $rules;
    }
}
