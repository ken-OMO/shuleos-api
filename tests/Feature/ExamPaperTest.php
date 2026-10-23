<?php

namespace Tests\Feature;

use App\Services\Assessment\AssessmentTypeService;
use App\Services\Assessment\ExamLearningAreaService;
use App\Services\Assessment\ExamPaperService;
use App\Services\Assessment\ExamService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
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

    public function test_result_free_draft_paper_can_be_updated_and_archived(): void
    {
        $service = app(ExamPaperService::class);
        $paper = $service->create($this->data(), $this->school->id);

        $updated = $service->update($paper, [
            'paper_name' => 'Updated paper', 'max_marks' => 60,
        ], $this->school->id);

        $this->assertSame('Updated paper', $updated->paper_name);
        $this->assertSame(60, $updated->max_marks);

        $service->delete($paper, $this->school->id, null);
        $this->assertTrue($paper->fresh()->is_deleted);
        $this->assertNotNull($paper->fresh()->deleted_at);
    }

    public function test_paper_update_checks_other_paper_marks_without_partial_changes(): void
    {
        $service = app(ExamPaperService::class);
        $paper = $service->create($this->data(), $this->school->id);
        $service->create([
            ...$this->data(), 'paper_number' => 2, 'paper_name' => 'Paper 2',
        ], $this->school->id);
        $before = $paper->fresh()->getAttributes();

        try {
            $service->update($paper, [
                'paper_name' => 'Must not persist', 'max_marks' => 60,
            ], $this->school->id);
            $this->fail('Combined paper marks must not exceed the area total.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('max_marks', $exception->errors());
        }

        $this->assertSame($before, $paper->fresh()->getAttributes());
    }

    public function test_stale_paper_cannot_be_changed_after_exam_publication(): void
    {
        $service = app(ExamPaperService::class);
        $paper = $service->create($this->data(), $this->school->id);
        $paper->load('examLearningArea.exam');

        app(ExamService::class)->transition(
            $paper->examLearningArea->exam, 'published'
        );
        $before = $paper->fresh()->getAttributes();

        foreach (['update', 'delete'] as $action) {
            try {
                if ($action === 'update') {
                    $service->update(
                        $paper, ['paper_name' => 'Rejected'], $this->school->id
                    );
                } else {
                    $service->delete($paper, $this->school->id, null);
                }
                $this->fail('Published paper configuration must be preserved.');
            } catch (HttpExceptionInterface $exception) {
                $this->assertSame(400, $exception->getStatusCode());
            }

            $this->assertSame($before, $paper->fresh()->getAttributes());
        }
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
