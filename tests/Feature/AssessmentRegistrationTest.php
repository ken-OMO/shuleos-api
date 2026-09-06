<?php

namespace Tests\Feature;

use App\Services\Assessment\AssessmentRegistrationService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\Database\GradeBuilder;
use Tests\Support\Database\LearnerBuilder;
use Tests\Support\Database\SchoolBuilder;
use Tests\Support\Database\StreamBuilder;
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
