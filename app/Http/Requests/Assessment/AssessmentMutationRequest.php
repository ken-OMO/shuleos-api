<?php

declare(strict_types=1);

namespace App\Http\Requests\Assessment;

use App\Services\Assessment\AssessmentTenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

abstract class AssessmentMutationRequest extends FormRequest
{
    public function authorize(): bool
    {
        AssessmentTenantContext::schoolId($this);

        return true;
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $clientOwnedSchool = $this->attributes
                ->get('client_supplied_school_id') === true;

            // Also reject explicit ownership when middleware is bypassed.
            if ($this->attributes->get('tenant_school_id') === null
                && $this->exists('school_id')) {
                $clientOwnedSchool = true;
            }

            if ($clientOwnedSchool) {
                $validator->errors()->add(
                    'school_id',
                    'The school_id field is prohibited.'
                );
            }
        });
    }

    protected function serverOwnedRules(): array
    {
        return [
            // Ignore the authoritative value injected by TenantMiddleware.
            'school_id' => ['exclude'],
            'id' => ['prohibited'],
            'created_by' => ['prohibited'],
            'updated_by' => ['prohibited'],
            'deleted_by' => ['prohibited'],
            'created_at' => ['prohibited'],
            'updated_at' => ['prohibited'],
            'deleted_at' => ['prohibited'],
            'is_deleted' => ['prohibited'],
        ];
    }
}
