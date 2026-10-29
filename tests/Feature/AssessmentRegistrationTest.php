<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Assessment\AssessmentRegistrationService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\Support\Database\GradeBuilder;
use Tests\Support\Database\LearnerBuilder;
use Tests\Support\Database\RoleBuilder;
use Tests\Support\Database\SchoolBuilder;
use Tests\Support\Database\StreamBuilder;
use Tests\Support\Database\UserBuilder;
use Tests\TestCase;

class AssessmentRegistrationTest extends TestCase
{
    use DatabaseTransactions;

    private object $school;

    private object $learner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->school = SchoolBuilder::create();
        $grade = GradeBuilder::create($this->school);
        $stream = StreamBuilder::create($this->school, $grade);
        $this->learner = LearnerBuilder::create(
            $this->school,
            $grade,
            $stream
        );
    }

    public function test_it_registers_an_active_tenant_learner(): void
    {
        $registration = app(AssessmentRegistrationService::class)->create(
            $this->data(),
            $this->school->id,
            null
        );

        $this->assertSame('pending', $registration->status);
        $this->assertSame($this->school->id, $registration->school_id);
    }

    public function test_it_rejects_duplicate_learner_registration(): void
    {
        $service = app(AssessmentRegistrationService::class);

        $service->create(
            $this->data(),
            $this->school->id,
            null
        );

        $this->expectException(ValidationException::class);

        $service->create(
            [
                ...$this->data(),
                'candidate_number' => 'C2',
                'registration_number' => 'R2',
            ],
            $this->school->id,
            null
        );
    }

    public function test_it_rejects_cross_school_learners(): void
    {
        $this->expectException(ValidationException::class);

        app(AssessmentRegistrationService::class)->create(
            $this->data(),
            (string) Str::uuid(),
            null
        );
    }

    public function test_verified_registration_cannot_be_downgraded(): void
    {
        $registration = app(AssessmentRegistrationService::class)->create(
            $this->data(),
            $this->school->id,
            null
        );

        $registration->update(['status' => 'verified']);

        $role = RoleBuilder::create();
        $actor = UserBuilder::create(
            $this->school, $role
        );
        $user = User::withoutGlobalScopes()->findOrFail($actor->id);

        $this->withoutMiddleware();
        Auth::setUser($user);

        $before = $registration->fresh()->getRawOriginal();

        $this->putJson(
            '/api/assessment-registrations/'.$registration->id,
            [
                'school_id' => $this->school->id,
                'status' => 'pending',
            ]
        )->assertUnprocessable();

        $this->assertSame(
            $before,
            $registration->fresh()->getRawOriginal()
        );
    }

    public function test_registration_transition_matrix(): void
    {
        $service = app(AssessmentRegistrationService::class);
        $registration = $service->create($this->data(), $this->school->id, null);
        $allowed = [
            'pending' => ['pending', 'registered', 'rejected', 'cancelled'],
            'registered' => ['registered', 'verified', 'rejected', 'cancelled'],
            'verified' => ['verified'],
            'rejected' => ['rejected'],
            'cancelled' => ['cancelled'],
        ];

        foreach ($allowed as $from => $targets) {
            foreach (array_keys($allowed) as $to) {
                $registration->refresh()->update(['status' => $from]);
                $before = $registration->fresh()->getRawOriginal();

                if (in_array($to, $targets, true)) {
                    $updated = $service->update(
                        $this->school->id, $registration->id, ['status' => $to]
                    );
                    $this->assertSame($to, $updated->status);
                } else {
                    $this->assertRegistrationValidationRejected(
                        fn () => $service->update(
                            $this->school->id, $registration->id, ['status' => $to]
                        ),
                        'status'
                    );
                    $this->assertSame($before, $registration->fresh()->getRawOriginal());
                }
            }
        }
    }

    public function test_registration_creation_cannot_skip_pending(): void
    {
        $service = app(AssessmentRegistrationService::class);

        foreach (['registered', 'verified', 'rejected', 'cancelled'] as $status) {
            $this->assertRegistrationValidationRejected(
                fn () => $service->create(
                    [...$this->data(), 'status' => $status],
                    $this->school->id, null
                ),
                'status'
            );
        }

        $this->assertSame(0, DB::table('assessment_registrations')
            ->where('school_id', $this->school->id)->count());
    }

    public function test_finalized_registration_identifiers_are_immutable(): void
    {
        $service = app(AssessmentRegistrationService::class);
        $registration = $service->create($this->data(), $this->school->id, null);

        foreach (['verified', 'rejected', 'cancelled'] as $status) {
            $registration->update(['status' => $status]);
            $before = $registration->fresh()->getRawOriginal();

            foreach (['candidate_number', 'registration_number'] as $field) {
                $this->assertRegistrationValidationRejected(
                    fn () => $service->update(
                        $this->school->id, $registration->id,
                        [$field => 'CHANGED']
                    ),
                    'status'
                );
                $this->assertSame($before, $registration->fresh()->getRawOriginal());
            }
        }
    }

    public function test_registration_deletion_preserves_verified_records(): void
    {
        $service = app(AssessmentRegistrationService::class);
        $registration = $service->create($this->data(), $this->school->id, null);
        $registration->update(['status' => 'verified']);
        $before = $registration->fresh()->getRawOriginal();

        $this->assertRegistrationValidationRejected(
            fn () => $service->delete($this->school->id, $registration->id, null),
            'status'
        );
        $this->assertSame($before, $registration->fresh()->getRawOriginal());

        $registration->update(['status' => 'pending']);
        $service->delete($this->school->id, $registration->id, null);

        $stored = DB::table('assessment_registrations')
            ->where('id', $registration->id)->first();

        $this->assertNotNull($stored);
        $this->assertSame('cancelled', $stored->status);
        $this->assertTrue((bool) $stored->is_deleted);
        $this->assertNotNull($stored->deleted_at);
        $this->assertSame($registration->candidate_number, $stored->candidate_number);
        $this->assertSame($registration->registration_number, $stored->registration_number);
    }

    public function test_registration_mutations_reject_another_schools_record(): void
    {
        $service = app(AssessmentRegistrationService::class);
        $registration = $service->create($this->data(), $this->school->id, null);
        $foreignSchool = SchoolBuilder::create();
        $before = $registration->fresh()->getRawOriginal();

        foreach ([
            fn () => $service->update(
                $foreignSchool->id, $registration->id, ['status' => 'registered']
            ),
            fn () => $service->delete($foreignSchool->id, $registration->id, null),
        ] as $operation) {
            try {
                $operation();
                $this->fail('Expected another school record to be unavailable.');
            } catch (HttpExceptionInterface $exception) {
                $this->assertSame(404, $exception->getStatusCode());
            }

            $this->assertSame($before, $registration->fresh()->getRawOriginal());
        }
    }

    public function test_duplicate_identifier_update_rolls_back_status_change(): void
    {
        $service = app(AssessmentRegistrationService::class);
        $first = $service->create($this->data(), $this->school->id, null);
        $second = $service->create([
            ...$this->data(),
            'assessment_type' => 'KJSEA',
            'candidate_number' => 'C-002',
            'registration_number' => 'R-002',
        ], $this->school->id, null);

        $before = $second->fresh()->getRawOriginal();

        foreach (['candidate_number', 'registration_number'] as $field) {
            $this->assertRegistrationValidationRejected(
                fn () => $service->update(
                    $this->school->id, $second->id,
                    [$field => $first->{$field}, 'status' => 'registered']
                ),
                $field
            );
            $this->assertSame($before, $second->fresh()->getRawOriginal());
        }
    }

    private function assertRegistrationValidationRejected(
        callable $operation,
        string $field
    ): void {
        try {
            $operation();
            $this->fail('Expected registration validation rejection.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($field, $exception->errors());
        }
    }

    private function data(): array
    {
        return [
            'learner_id' => $this->learner->id,
            'assessment_type' => 'KPSEA',
            'assessment_year' => 2026,
            'candidate_number' => 'C-001',
            'registration_number' => 'R-001',
        ];
    }
}
