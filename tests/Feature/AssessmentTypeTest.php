<?php

namespace Tests\Feature;

use App\Services\Assessment\AssessmentTypeService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Validation\ValidationException;
use Tests\Support\Database\SchoolBuilder;
use Tests\TestCase;

class AssessmentTypeTest extends TestCase
{
    use DatabaseTransactions;

    private object $school;

    protected function setUp(): void
    {
        parent::setUp();

        $this->school = SchoolBuilder::create();
    }

    public function test_it_creates_a_tenant_assessment_type(): void
    {
        $assessmentType = app(AssessmentTypeService::class)->create(
            ['assessment_type_name' => 'Formative'],
            $this->school->id
        );

        $this->assertSame($this->school->id, $assessmentType->school_id);
        $this->assertTrue($assessmentType->active);
    }

    public function test_it_rejects_case_insensitive_duplicates(): void
    {
        $service = app(AssessmentTypeService::class);

        $service->create(
            ['assessment_type_name' => 'Summative'],
            $this->school->id
        );

        $this->expectException(ValidationException::class);

        $service->create(
            ['assessment_type_name' => 'summative'],
            $this->school->id
        );
    }

    public function test_same_name_is_allowed_in_another_school(): void
    {
        $service = app(AssessmentTypeService::class);
        $otherSchool = SchoolBuilder::create();

        $service->create(
            ['assessment_type_name' => 'CAT'],
            $this->school->id
        );

        $otherAssessmentType = $service->create(
            ['assessment_type_name' => 'CAT'],
            $otherSchool->id
        );

        $this->assertNotSame(
            $this->school->id,
            $otherAssessmentType->school_id
        );
    }
}
