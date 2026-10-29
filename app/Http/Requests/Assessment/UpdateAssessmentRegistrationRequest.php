<?php

declare(strict_types=1);

namespace App\Http\Requests\Assessment;

final class UpdateAssessmentRegistrationRequest extends AssessmentMutationRequest
{
    public function rules(): array
    {
        return array_merge(
            ['candidate_number' => 'sometimes|string|max:100', 'registration_number' => 'sometimes|string|max:100', 'status' => 'sometimes|in:pending,registered,verified,rejected,cancelled'],
            $this->serverOwnedRules()
        );
    }
}
