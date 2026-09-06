<?php

declare(strict_types=1);

namespace App\Http\Requests\Boarding;

use Illuminate\Foundation\Http\FormRequest;

class StoreBedAllocationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'learner_id' => [
                'required',
                'uuid',
            ],
            'bed_id' => [
                'required',
                'uuid',
            ],

            /*
             * TenantMiddleware supplies the authoritative school context.
             * It must still agree with the authenticated school authority.
             */
            'school_id' => [
                'required',
                'uuid',
                function (
                    string $attribute,
                    mixed $value,
                    \Closure $fail
                ): void {
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

            /*
             * Allocation authority and lifecycle fields are server-owned.
             */
            'allocated_by' => ['prohibited'],
            'allocation_date' => ['prohibited'],
            'release_date' => ['prohibited'],
            'active' => ['prohibited'],
            'id' => ['prohibited'],
            'created_at' => ['prohibited'],
            'updated_at' => ['prohibited'],
        ];
    }
}
