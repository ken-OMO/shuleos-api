<?php

declare(strict_types=1);

namespace App\Http\Requests\Assessment;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ReportCardSelectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        $schoolId = auth()->user()?->school_id;
        $tenant = $this->attributes->get('tenant_school_id');

        return filled($schoolId)
            && ($tenant === null || (string) $tenant === (string) $schoolId);
    }

    public function rules(): array
    {
        $schoolId = (string) auth()->user()->school_id;

        return [
            // TenantMiddleware injects this field after recording client input.
            'school_id' => [
                'sometimes',
                function (string $attribute, mixed $value, \Closure $fail) use ($schoolId): void {
                    if ($this->attributes->get('client_supplied_school_id') === true
                        || (string) $value !== $schoolId) {
                        $fail('The school_id field is prohibited.');
                    }
                },
            ],
            'exam_id' => [
                'required', 'uuid',
                Rule::exists('exams', 'id')->where('school_id', $schoolId),
            ],
            'learner_id' => [
                'sometimes', 'nullable', 'uuid',
                Rule::exists('learners', 'id')->where('school_id', $schoolId),
            ],
            'grade_id' => [
                'sometimes', 'nullable', 'uuid',
                Rule::exists('grades', 'id')->where('school_id', $schoolId),
            ],
            'stream_id' => [
                'sometimes', 'nullable', 'uuid',
                Rule::exists('streams', 'id')->where('school_id', $schoolId),
            ],
            'class_teacher_comment' => 'sometimes|nullable|string',
            'principal_comment' => 'sometimes|nullable|string',
            'response_mode' => 'sometimes|in:details,summary',
            'id' => ['prohibited'],
            'generated_by' => ['prohibited'],
            'published_by' => ['prohibited'],
            'published_at' => ['prohibited'],
            'created_by' => ['prohibited'],
            'created_at' => ['prohibited'],
            'updated_at' => ['prohibited'],
        ];
    }
}
