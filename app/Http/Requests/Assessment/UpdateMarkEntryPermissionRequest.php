<?php

declare(strict_types=1);

namespace App\Http\Requests\Assessment;

final class UpdateMarkEntryPermissionRequest extends AssessmentMutationRequest
{
    public function rules(): array
    {
        return array_merge(
            [
                'active' => 'sometimes|boolean',
                'opens_at' => 'nullable|date',
                'closes_at' => 'nullable|date',
            ],
            $this->serverOwnedRules()
        );
    }
}
