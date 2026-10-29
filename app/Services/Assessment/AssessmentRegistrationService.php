<?php

namespace App\Services\Assessment;

use App\Models\AssessmentRegistration;
use App\Models\Learner;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AssessmentRegistrationService
{
    public function create(array $d, string $school, ?string $user): AssessmentRegistration
    {
        return DB::transaction(function () use ($d, $school, $user) {
            $this->lockWrites($school);

            return $this->createWritable($d, $school, $user);
        });
    }

    public function update(string $school, string $id, array $data): AssessmentRegistration
    {
        return DB::transaction(function () use ($school, $id, $data) {
            $this->lockWrites($school);
            $registration = $this->lockedRegistration($school, $id);

            $values = Validator::make($data, [
                'candidate_number' => ['sometimes', 'string', 'max:100', 'regex:/\S/'],
                'registration_number' => ['sometimes', 'string', 'max:100', 'regex:/\S/'],
                'status' => 'sometimes|in:pending,registered,verified,rejected,cancelled',
            ])->validate();

            $changes = [];
            foreach ($values as $field => $value) {
                if ($registration->getAttribute($field) !== $value) {
                    $changes[$field] = $value;
                }
            }

            if ($changes === []) {
                return $registration;
            }

            if (in_array($registration->status, ['verified', 'rejected', 'cancelled'], true)) {
                throw ValidationException::withMessages([
                    'status' => 'Finalized registrations cannot be changed.',
                ]);
            }

            $target = $changes['status'] ?? $registration->status;
            $allowed = [
                'pending' => ['registered', 'rejected', 'cancelled'],
                'registered' => ['verified', 'rejected', 'cancelled'],
            ];

            if ($target !== $registration->status
                && ! in_array($target, $allowed[$registration->status] ?? [], true)) {
                throw ValidationException::withMessages([
                    'status' => "Cannot change registration status from {$registration->status} to {$target}.",
                ]);
            }

            foreach (['candidate_number', 'registration_number'] as $field) {
                if (array_key_exists($field, $changes)
                    && AssessmentRegistration::withoutGlobalScopes()->current()
                        ->where('school_id', $school)
                        ->where('assessment_year', $registration->assessment_year)
                        ->whereKeyNot($registration->id)
                        ->where($field, $changes[$field])->exists()) {
                    throw ValidationException::withMessages([
                        $field => 'This value is already in use.',
                    ]);
                }
            }

            $registration->update($changes);

            return $registration;
        });
    }

    public function delete(string $school, string $id, ?string $actor): void
    {
        DB::transaction(function () use ($school, $id, $actor): void {
            $this->lockWrites($school);
            $registration = $this->lockedRegistration($school, $id);

            if ($registration->status === 'verified') {
                throw ValidationException::withMessages([
                    'status' => 'Verified registrations cannot be deleted.',
                ]);
            }

            if ($actor !== null && ! DB::table('users')
                ->where('school_id', $school)->where('id', $actor)->exists()) {
                throw ValidationException::withMessages([
                    'deleted_by' => 'The deletion actor must belong to this school.',
                ]);
            }

            $registration->update([
                'status' => 'cancelled',
                'is_deleted' => true,
                'deleted_at' => now(),
                'deleted_by' => $actor,
            ]);
        });
    }

    private function lockWrites(string $school): void
    {
        app(ExamResultLifecycleService::class)->assertSchoolWritable($school);

        // Serialize registration uniqueness checks within this school.
        // The transaction releases the advisory lock automatically.
        DB::selectOne(
            'SELECT pg_advisory_xact_lock(hashtextextended(?, 0))',
            ['assessment_registrations:'.$school]
        );
    }

    private function lockedRegistration(string $school, string $id): AssessmentRegistration
    {
        $registration = AssessmentRegistration::withoutGlobalScopes()->current()
            ->where('school_id', $school)->whereKey($id)
            ->lockForUpdate()->first();

        abort_if($registration === null, 404, 'Assessment registration not found.');

        return $registration;
    }

    private function createWritable(array $d, string $school, ?string $user): AssessmentRegistration
    {
        if (($d['status'] ?? 'pending') !== 'pending') {
            throw ValidationException::withMessages([
                'status' => 'New registrations must start as pending.',
            ]);
        }

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
