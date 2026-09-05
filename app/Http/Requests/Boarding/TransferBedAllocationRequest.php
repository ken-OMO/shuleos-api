<?php

declare(strict_types=1);

namespace App\Http\Requests\Boarding;

use Illuminate\Foundation\Http\FormRequest;

class TransferBedAllocationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'destination_bed_id' => [
                'required',
                'uuid',
            ],
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
            'reason' => [
                'nullable',
                'string',
                'max:500',
            ],

            /*
             * Source lifecycle, authority and history fields are
             * authoritative server state.
             */
            'id' => ['prohibited'],
            'learner_id' => ['prohibited'],
            'bed_id' => ['prohibited'],
            'source_allocation_id' => ['prohibited'],
            'destination_allocation_id' => ['prohibited'],
            'status' => ['prohibited'],
            'active' => ['prohibited'],
            'allocation_date' => ['prohibited'],
            'release_date' => ['prohibited'],
            'event_id' => ['prohibited'],
            'event_type' => ['prohibited'],
            'from_status' => ['prohibited'],
            'to_status' => ['prohibited'],
            'effective_date' => ['prohibited'],
            'allocated_by' => ['prohibited'],
            'changed_by' => ['prohibited'],
            'changed_at' => ['prohibited'],
            'created_at' => ['prohibited'],
            'updated_at' => ['prohibited'],
        ];
    }
}
