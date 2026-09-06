<?php

namespace Tests\Feature;

use App\Services\Assessment\AssessmentTypeService;
use App\Services\Assessment\ExamService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Validation\ValidationException;
use Tests\Support\Database\AcademicYearBuilder;
use Tests\Support\Database\SchoolBuilder;
use Tests\Support\Database\TermBuilder;
use Tests\TestCase;

class ExamTest extends TestCase
{
    use DatabaseTransactions;

    private object $school;

    private object $assessmentType;

    private object $academicYear;

    private object $term;

    protected function setUp(): void
    {
        parent::setUp();

        $this->school = SchoolBuilder::create();

        $this->assessmentType = app(AssessmentTypeService::class)->create(
            ['assessment_type_name' => 'Formative'],
            $this->school->id
        );

        $this->academicYear = AcademicYearBuilder::create($this->school);

        $this->term = TermBuilder::create(
            $this->school,
            $this->academicYear
        );
    }

    public function test_it_creates_a_draft_exam_in_the_term(): void
    {
        $exam = app(ExamService::class)->create(
            $this->data(),
            $this->school->id,
            null
        );

        $this->assertSame('draft', $exam->status);
        $this->assertSame($this->term->id, $exam->term_id);
    }

    public function test_it_rejects_dates_outside_the_term(): void
    {
        $this->expectException(ValidationException::class);

        app(ExamService::class)->create(
            [
                ...$this->data(),
                'start_date' => '2026-08-02',
                'end_date' => '2026-08-03',
            ],
            $this->school->id,
            null
        );
    }

    public function test_it_enforces_exam_lifecycle(): void
    {
        $service = app(ExamService::class);

        $exam = $service->create(
            $this->data(),
            $this->school->id,
            null
        );

        $service->transition($exam, 'published');

        $this->assertSame(
            'published',
            $exam->fresh()->status
        );

        $this->expectException(ValidationException::class);

        $service->transition(
            $exam->fresh(),
            'draft'
        );
    }

    private function data(): array
    {
        return [
            'exam_name' => 'Term Two Exam',
            'assessment_type_id' => $this->assessmentType->id,
            'academic_year_id' => $this->academicYear->id,
            'term_id' => $this->term->id,
            'start_date' => '2026-07-20',
            'end_date' => '2026-07-25',
        ];
    }
}
