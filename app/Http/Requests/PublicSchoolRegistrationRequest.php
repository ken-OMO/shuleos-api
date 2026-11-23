<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PublicSchoolRegistrationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $data = $this->all();
        foreach (['school_name', 'admin.first_name', 'admin.last_name'] as $field) {
            if (is_string(data_get($data, $field))) {
                data_set($data, $field, trim(strip_tags(data_get($data, $field))));
            }
        }
        foreach (['email', 'admin.email'] as $field) {
            if (is_string(data_get($data, $field))) {
                data_set($data, $field, strtolower(trim(data_get($data, $field))));
            }
        }
        $this->replace($data);
    }

    public function rules(): array
    {
        return [
            'school_name' => 'required|string|max:255',
            'operating_mode' => 'required|in:day,boarding,day_and_boarding',
            'package_id' => ['bail', 'nullable', 'required_if:trial_boarding,true,1', 'required_if:trial_elections,true,1', 'required_if:trial_transport,true,1',
                'uuid', Rule::exists('subscription_packages', 'id')->where('active', true)],
            'trial_elections' => 'sometimes|boolean',
            'trial_boarding' => ['sometimes', 'boolean', Rule::prohibitedIf(
                fn () => $this->boolean('trial_boarding') && ! in_array($this->input('operating_mode'), ['boarding', 'day_and_boarding'], true)
            )],
            'trial_transport' => ['sometimes', 'boolean', Rule::prohibitedIf(
                fn () => $this->boolean('trial_transport') && ! in_array($this->input('operating_mode'), ['day', 'day_and_boarding'], true)
            )],
            'admin' => 'required|array:first_name,last_name,email',
            'admin.first_name' => 'required|string|max:100',
            'admin.last_name' => 'required|string|max:100',
            'admin.email' => 'required|email|max:255',
            'email' => 'nullable|email|max:255', 'phone' => 'nullable|string|max:30',
            'postal_address' => 'nullable|string|max:255', 'physical_address' => 'nullable|string|max:255',
            'id' => 'prohibited', 'school_id' => 'prohibited', 'school_code' => 'prohibited',
            'status' => 'prohibited', 'reviewed_by' => 'prohibited', 'reviewed_at' => 'prohibited',
            'password' => 'prohibited', 'role_id' => 'prohibited', 'username' => 'prohibited',
        ];
    }
}
