<?php

declare(strict_types=1);

namespace App\Http\Requests\TeacherDuty;

use Illuminate\Foundation\Http\FormRequest;

class UpdateTeacherDutyDailyReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'summary' => ['present', 'nullable', 'string'],

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
            'duty_period_id' => ['prohibited'],
            'report_date' => ['prohibited'],
            'status' => ['prohibited'],
            'deadline_at' => ['prohibited'],
            'created_by' => ['prohibited'],
            'submitted_by' => ['prohibited'],
            'submitted_at' => ['prohibited'],
            'late' => ['prohibited'],
            'state' => ['prohibited'],
            'actor_user_id' => ['prohibited'],
            'user_id' => ['prohibited'],
        ];
    }
}
