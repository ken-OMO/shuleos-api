<?php

declare(strict_types=1);

namespace App\Http\Requests\TeacherDuty;

use Illuminate\Foundation\Http\FormRequest;

final class StoreTeacherDutyAssignmentRequest extends FormRequest
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
            'teacher_id' => [
                'required',
                'uuid',
            ],
            'id' => ['prohibited'],
            'duty_period_id' => ['prohibited'],
            'active' => ['prohibited'],
            'assigned_by' => ['prohibited'],
            'ended_by' => ['prohibited'],
            'ended_at' => ['prohibited'],
            'end_reason' => ['prohibited'],
            'reason' => ['prohibited'],
            'created_at' => ['prohibited'],
            'updated_at' => ['prohibited'],
        ];
    }
}
