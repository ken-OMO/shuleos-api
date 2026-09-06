<?php

declare(strict_types=1);

namespace App\Http\Requests\Boarding;

use Illuminate\Foundation\Http\FormRequest;

class StoreHostelRoomRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'room_name' => [
                'required',
                'string',
                'max:150',
            ],
            'floor_number' => [
                'nullable',
                'integer',
            ],
            'capacity' => [
                'nullable',
                'integer',
                'min:1',
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
                        $fail(
                            'The school context is invalid.'
                        );
                    }
                },
            ],            'hostel_id' => ['prohibited'],
            'active' => ['prohibited'],
            'is_deleted' => ['prohibited'],
            'deleted_at' => ['prohibited'],
            'deleted_by' => ['prohibited'],
        ];
    }
}
