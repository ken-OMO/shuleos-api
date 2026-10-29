<?php

declare(strict_types=1);

namespace App\Http\Requests\Assessment;

final class StoreMarkEntryPermissionRequest extends AssessmentMutationRequest
{
    public function rules(): array
    {
        return array_merge(
            [
                'school_id' => 'sometimes|uuid|exists:schools,id',
                'exam_id' => 'required|uuid|exists:exams,id',
                'role_name' => 'required|string|max:255',
                'active' => 'sometimes|boolean',
                'opens_at' => 'nullable|date',
                'closes_at' => 'nullable|date',
            ],
            $this->serverOwnedRules()
        );
    }
}
