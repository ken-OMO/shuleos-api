<?php

namespace App\Services\Assessment;

use App\Models\AssessmentRegistration;
use App\Models\Learner;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AssessmentRegistrationService
{
    public function create(array $d, string $school, ?string $user): AssessmentRegistration
    {
        $learner = Learner::whereKey($d['learner_id'])->where('school_id', $school)->where('active', true)->where('is_deleted', false)->first();
        if (! $learner) {
            throw ValidationException::withMessages(['learner_id' => 'The learner is inactive or outside this school.']);
        }$type = trim($d['assessment_type']);
        $q = AssessmentRegistration::current()->where('school_id', $school)->where('assessment_year', $d['assessment_year']);
        if ((clone $q)->where('learner_id', $learner->id)->whereRaw('LOWER(assessment_type) = ?', [mb_strtolower($type)])->exists()) {
            throw ValidationException::withMessages(['learner_id' => 'The learner is already registered for this assessment and year.']);
        }if ((clone $q)->where('candidate_number', $d['candidate_number'])->exists()) {
            throw ValidationException::withMessages(['candidate_number' => 'This candidate number is already in use.']);
        }if ((clone $q)->where('registration_number', $d['registration_number'])->exists()) {
            throw ValidationException::withMessages(['registration_number' => 'This registration number is already in use.']);
        }

        return AssessmentRegistration::create([...$d, 'id' => (string) Str::uuid(), 'school_id' => $school, 'assessment_type' => $type, 'status' => $d['status'] ?? 'pending', 'created_by' => $user ?? $d['created_by'] ?? null, 'is_deleted' => false, 'created_at' => now()]);
    }
}
