<?php

declare(strict_types=1);

namespace App\Http\Requests\Teacher;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateTeacherRequest extends FormRequest
{
    /**
     * Determine whether the user can make this request.
     *
     * Authorization policies will replace this later.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Validation rules.
     */
    public function rules(): array
    {
        $teacherId = $this->route('id');

        return [

            'school_id' => [
                'sometimes',
                'uuid',
                'exists:schools,id',
            ],

            'user_id' => [
                'nullable',
                'uuid',
                'exists:users,id',
            ],

            'tsc_no' => [
                'sometimes',
                'string',
                'max:100',
                Rule::unique('teachers', 'tsc_no')
                    ->ignore($teacherId, 'id'),
            ],

            'staff_no' => [
                'sometimes',
                'string',
                'max:100',
                Rule::unique('teachers', 'staff_no')
                    ->ignore($teacherId, 'id'),
            ],

            'gender' => [
                'nullable',
                'string',
                'max:20',
            ],

            'designation' => [
                'nullable',
                'string',
                'max:100',
            ],

            'employment_type' => [
                'nullable',
                'string',
                'max:100',
            ],

            'phone' => [
                'nullable',
                'string',
                'max:20',
            ],

            'email' => [
                'nullable',
                'email',
                'max:255',
                Rule::unique('teachers', 'email')
                    ->ignore($teacherId, 'id'),
            ],

            'national_id' => [
                'nullable',
                'string',
                'max:50',
            ],

            'date_joined' => [
                'nullable',
                'date',
            ],

            'active' => [
                'sometimes',
                'boolean',
            ],

        ];
    }

    /**
     * Custom validation messages.
     */
    public function messages(): array
    {
        return [

            'school_id.exists' =>
                'The selected school does not exist.',

            'user_id.exists' =>
                'The selected user account does not exist.',

            'tsc_no.unique' =>
                'This TSC Number already exists.',

            'staff_no.unique' =>
                'This Staff Number already exists.',

            'email.email' =>
                'Please enter a valid email address.',

            'email.unique' =>
                'This email address is already assigned to another teacher.',

        ];
    }

    /**
     * Human-readable attribute names.
     */
    public function attributes(): array
    {
        return [

            'school_id' => 'school',

            'user_id' => 'user account',

            'tsc_no' => 'TSC Number',

            'staff_no' => 'staff number',

            'employment_type' => 'employment type',

            'national_id' => 'National ID',

            'date_joined' => 'date joined',

        ];
    }
}
