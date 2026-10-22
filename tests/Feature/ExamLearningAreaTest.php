<?php

namespace Tests\Feature;

use App\Services\Assessment\AssessmentTypeService;
use App\Services\Assessment\ExamLearningAreaService;
use App\Services\Assessment\ExamPaperService;
use App\Services\Assessment\ExamService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\Support\Database\AcademicYearBuilder;
use Tests\Support\Database\LearningAreaBuilder;
use Tests\Support\Database\SchoolBuilder;
use Tests\Support\Database\TermBuilder;
use Tests\TestCase;

class ExamLearningAreaTest extends TestCase
{
    use DatabaseTransactions;

    private object $school;

    private object $exam;

    private object $learningArea;

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

        $this->exam = app(ExamService::class)->create(
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

        $this->learningArea = LearningAreaBuilder::create();
    }

    public function test_it_attaches_an_active_area_to_a_draft_exam(): void
    {
        $examLearningArea = app(ExamLearningAreaService::class)->create(
            $this->data(),
            $this->school->id
        );

        $this->assertSame(2, $examLearningArea->number_of_papers);
        $this->assertSame(100, $examLearningArea->total_marks);
    }

    public function test_it_rejects_duplicate_exam_areas(): void
    {
        $service = app(ExamLearningAreaService::class);

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

    public function test_it_rejects_cross_school_exams(): void
    {
        $this->expectException(ValidationException::class);

        app(ExamLearningAreaService::class)->create(
            $this->data(),
            (string) Str::uuid()
        );
    }

    public function test_paperless_draft_area_can_be_updated_and_archived(): void
    {
        $service = app(ExamLearningAreaService::class);
        $area = $service->create($this->data(), $this->school->id);

        $updated = $service->update($area, [
            'number_of_papers' => 1, 'total_marks' => 80,
        ], $this->school->id);

        $this->assertSame(1, $updated->number_of_papers);
        $this->assertSame(80, $updated->total_marks);

        $service->delete($area, $this->school->id, null);
        $this->assertTrue($area->fresh()->is_deleted);
        $this->assertNotNull($area->fresh()->deleted_at);
    }

    public function test_stale_area_cannot_be_changed_after_exam_publication(): void
    {
        $service = app(ExamLearningAreaService::class);
        $area = $service->create($this->data(), $this->school->id);
        $area->load('exam');

        app(ExamService::class)->transition($this->exam, 'published');
        $before = $area->fresh()->getAttributes();
        $this->assertSame('draft', $area->exam->status);

        foreach (['update', 'delete'] as $action) {
            try {
                if ($action === 'update') {
                    $service->update(
                        $area, ['total_marks' => 80], $this->school->id
                    );
                } else {
                    $service->delete($area, $this->school->id, null);
                }
                $this->fail('Published exam configuration must be preserved.');
            } catch (HttpExceptionInterface $exception) {
                $this->assertSame(400, $exception->getStatusCode());
            }

            $this->assertSame($before, $area->fresh()->getAttributes());
        }
    }

    public function test_area_with_papers_cannot_be_changed_or_archived(): void
    {
        $service = app(ExamLearningAreaService::class);
        $area = $service->create($this->data(), $this->school->id);

        app(ExamPaperService::class)->create([
            'exam_learning_area_id' => $area->id,
            'paper_name' => 'Paper 1',
            'paper_number' => 1,
            'max_marks' => 50,
        ], $this->school->id);

        $before = $area->fresh()->getAttributes();

        foreach (['update', 'delete'] as $action) {
            try {
                if ($action === 'update') {
                    $service->update(
                        $area, ['total_marks' => 80], $this->school->id
                    );
                } else {
                    $service->delete($area, $this->school->id, null);
                }
                $this->fail('An area with papers must be preserved.');
            } catch (HttpExceptionInterface $exception) {
                $this->assertSame(400, $exception->getStatusCode());
            }

            $this->assertSame($before, $area->fresh()->getAttributes());
        }
    }

    private function data(): array
    {
        return [
            'exam_id' => $this->exam->id,
            'learning_area_id' => $this->learningArea->id,
            'number_of_papers' => 2,
            'total_marks' => 100,
        ];
    }
}
