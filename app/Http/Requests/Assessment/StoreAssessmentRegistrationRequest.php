<?php

declare(strict_types=1);

namespace App\Http\Requests\Assessment;

final class StoreAssessmentRegistrationRequest extends AssessmentMutationRequest
{
    public function rules(): array
    {
        return array_merge(
            ['school_id' => 'sometimes|uuid|exists:schools,id', 'learner_id' => 'required|uuid|exists:learners,id', 'assessment_type' => 'required|string|max:255', 'assessment_year' => 'required|integer|min:2000|max:2100', 'candidate_number' => 'required|string|max:100', 'registration_number' => 'required|string|max:100', 'status' => 'sometimes|in:pending', 'created_by' => 'sometimes|nullable|uuid|exists:users,id'],
            $this->serverOwnedRules()
        );
    }
}
