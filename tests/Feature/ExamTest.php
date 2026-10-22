<?php

namespace Tests\Feature;

use App\Services\Assessment\AssessmentTypeService;
use App\Services\Assessment\ExamService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
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

    public function test_rejected_mixed_update_preserves_status_and_details(): void
    {
        $service = app(ExamService::class);
        $exam = $service->create($this->data(), $this->school->id, null);
        $before = $exam->fresh()->getAttributes();

        try {
            $service->update($exam, [
                'status' => 'published', 'exam_name' => 'Rejected name',
                'active' => false,
            ]);
            $this->fail('Mixed publication and detail changes must be rejected.');
        } catch (HttpExceptionInterface $exception) {
            $this->assertSame(400, $exception->getStatusCode());
        }

        $this->assertSame($before, $exam->fresh()->getAttributes());
    }

    public function test_partial_date_update_checks_the_stored_other_boundary(): void
    {
        $service = app(ExamService::class);
        $exam = $service->create($this->data(), $this->school->id, null);
        $before = $exam->fresh()->getAttributes();

        foreach ([
            ['end_date' => '2026-07-19'],
            ['start_date' => '2026-07-26'],
            ['end_date' => '2026-08-03'],
        ] as $values) {
            try {
                $service->update($exam, $values);
                $this->fail('Invalid merged exam dates must be rejected.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('start_date', $exception->errors());
            }
            $this->assertSame($before, $exam->fresh()->getAttributes());
        }
    }

    public function test_draft_update_and_status_only_transition_succeed(): void
    {
        $service = app(ExamService::class);
        $exam = $service->create($this->data(), $this->school->id, null);

        $updated = $service->update($exam, [
            'exam_name' => ' Updated exam ',
            'end_date' => '2026-07-24',
        ]);
        $this->assertSame('Updated exam', $updated->exam_name);
        $this->assertSame('2026-07-24', $updated->end_date->toDateString());

        $published = $service->update($exam, ['status' => 'published']);
        $this->assertSame('published', $published->status);

        // The original instance still says draft; stored state must win.
        try {
            $service->update($exam, ['exam_name' => 'Stale overwrite']);
            $this->fail('A stale draft model must not change published details.');
        } catch (HttpExceptionInterface $exception) {
            $this->assertSame(400, $exception->getStatusCode());
        }
        $this->assertSame('Updated exam', $exam->fresh()->exam_name);
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
