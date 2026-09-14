<?php

declare(strict_types=1);

namespace App\Http\Requests\TeacherDuty;

use Illuminate\Foundation\Http\FormRequest;

final class StoreTeacherDutyOccurrenceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            /*
             * TenantMiddleware supplies school_id after recording whether
             * the client attempted to own that field.
             */
            'school_id' => [
                'required',
                'uuid',
                function (
                    string $attribute,
                    mixed $value,
                    \Closure $fail
                ): void {
                    if (
                        $this->attributes->get(
                            'client_supplied_school_id',
                            false
                        ) === true
                    ) {
                        $fail('The school_id field is prohibited.');

                        return;
                    }

                    $user = $this->user();

                    if (
                        ! $user
                        || ! $user->school_id
                        || (string) $value !== (string) $user->school_id
                    ) {
                        $fail('The school context is invalid.');
                    }
                },
            ],
            'occurrence_category_id' => [
                'required',
                'uuid',
            ],
            'occurrence_date' => [
                'required',
                'date_format:Y-m-d',
            ],
            'occurrence_time' => [
                'nullable',
                'date_format:H:i',
            ],
            'description' => [
                'required',
                'string',
                'max:5000',
            ],
            'id' => ['prohibited'],
            'duty_period_id' => ['prohibited'],
            'recorded_by' => ['prohibited'],
            'created_by' => ['prohibited'],
            'deactivated_by' => ['prohibited'],
            'deactivated_at' => ['prohibited'],
            'is_canonical' => ['prohibited'],
            'active' => ['prohibited'],
            'created_at' => ['prohibited'],
            'updated_at' => ['prohibited'],
        ];
    }
}
