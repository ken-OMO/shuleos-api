<?php

declare(strict_types=1);

namespace App\Services\StudentElection;

use Illuminate\Validation\ValidationException;

/** Legacy database columns do not configure the hardened election workflow. */
final class StudentElectionSettingsPolicy
{
    public function validate(array $data): void
    {
        $errors = [];
        foreach (['allow_self_nomination', 'require_candidate_approval', 'show_live_turnout'] as $field) {
            if (array_key_exists($field, $data)) {
                $errors[$field] = 'This legacy setting is not configurable. Use the versioned Elections workflow.';
            }
        }
        if (array_key_exists('results_visibility', $data) && $data['results_visibility'] !== 'hidden') {
            $errors['results_visibility'] = 'Only hidden is supported; results become visible through controlled declaration.';
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }
}
