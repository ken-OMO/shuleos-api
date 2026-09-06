<?php

namespace Tests\Feature;

use App\Services\Assessment\AssessmentTypeService;
use App\Services\Assessment\ExamLearningAreaService;
use App\Services\Assessment\ExamPaperService;
use App\Services\Assessment\ExamService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Validation\ValidationException;
use Tests\Support\Database\AcademicYearBuilder;
use Tests\Support\Database\LearningAreaBuilder;
use Tests\Support\Database\SchoolBuilder;
use Tests\Support\Database\TermBuilder;
use Tests\TestCase;

class ExamPaperTest extends TestCase
{
    use DatabaseTransactions;

    private object $school;

    private object $examLearningArea;

    protected function setUp(): void
    {
        parent::setUp();

        $this->school = SchoolBuilder::create();

        $assessmentType = app(AssessmentTypeService::class)->create(
            ['assessment_type_name' => 'Formative'],
            $this->school->id
        );

        $academicYear = AcademicYearBuilder::create($this->school);
        $term = TermBuilder::create($this->school, $academicYear);

        $exam = app(ExamService::class)->create(
            [
                'exam_name' => 'Term Two Exam',
                'assessment_type_id' => $assessmentType->id,
                'academic_year_id' => $academicYear->id,
                'term_id' => $term->id,
                'start_date' => '2026-07-20',
                'end_date' => '2026-07-25',
            ],
            $this->school->id,
            null
        );

        $learningArea = LearningAreaBuilder::create();

        $this->examLearningArea = app(
            ExamLearningAreaService::class
        )->create(
            [
                'exam_id' => $exam->id,
                'learning_area_id' => $learningArea->id,
                'number_of_papers' => 2,
                'total_marks' => 100,
            ],
            $this->school->id
        );
    }

    public function test_it_creates_a_paper_within_declared_limits(): void
    {
        $paper = app(ExamPaperService::class)->create(
            $this->data(),
            $this->school->id
        );

        $this->assertSame(1, $paper->paper_number);
        $this->assertSame(50, $paper->max_marks);
    }

    public function test_it_rejects_duplicate_paper_numbers(): void
    {
        $service = app(ExamPaperService::class);

        $service->create(
            $this->data(),
            $this->school->id
        );

        $this->expectException(ValidationException::class);

        $service->create(
            $this->data(),
            $this->school->id
        );
    }

    public function test_it_rejects_marks_above_subject_total(): void
    {
        $service = app(ExamPaperService::class);

        $service->create(
            $this->data(),
            $this->school->id
        );

        $this->expectException(ValidationException::class);

        $service->create(
            [
                ...$this->data(),
                'paper_number' => 2,
                'max_marks' => 60,
            ],
            $this->school->id
        );
    }

    private function data(): array
    {
        return [
            'exam_learning_area_id' => $this->examLearningArea->id,
            'paper_name' => 'Paper 1',
            'paper_number' => 1,
            'max_marks' => 50,
        ];
    }
}
