<?php

namespace Tests\Feature;

use App\Services\Assessment\AssessmentTypeService;
use App\Services\Assessment\ExamService;
use App\Services\Assessment\MarkEntryPermissionService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Validation\ValidationException;
use Tests\Support\Database\AcademicYearBuilder;
use Tests\Support\Database\SchoolBuilder;
use Tests\Support\Database\TermBuilder;
use Tests\TestCase;

class MarkEntryPermissionTest extends TestCase
{
    use DatabaseTransactions;

    private object $school;

    private object $exam;

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

        app(ExamService::class)->transition(
            $this->exam,
            'published'
        );

        $this->exam = $this->exam->fresh();
    }

    public function test_it_grants_a_role_permission_for_a_published_exam(): void
    {
        $permission = app(MarkEntryPermissionService::class)->create(
            [
                'exam_id' => $this->exam->id,
                'role_name' => 'Teacher',
                'opens_at' => now()->subHour(),
                'closes_at' => now()->addHour(),
            ],
            $this->school->id
        );

        $this->assertSame('teacher', $permission->role_name);
        $this->assertTrue($permission->isOpen());
    }

    public function test_it_rejects_duplicate_role_permissions(): void
    {
        $service = app(MarkEntryPermissionService::class);

        $service->create(
            [
                'exam_id' => $this->exam->id,
                'role_name' => 'teacher',
            ],
            $this->school->id
        );

        $this->expectException(ValidationException::class);

        $service->create(
            [
                'exam_id' => $this->exam->id,
                'role_name' => 'TEACHER',
            ],
            $this->school->id
        );
    }

    public function test_it_rejects_permissions_for_draft_exams(): void
    {
        $draftExam = app(ExamService::class)->create(
            [
                'exam_name' => 'Draft Exam',
                'assessment_type_id' => $this->exam->assessment_type_id,
                'academic_year_id' => $this->exam->academic_year_id,
                'term_id' => $this->exam->term_id,
                'start_date' => '2026-07-20',
                'end_date' => '2026-07-25',
            ],
            $this->school->id,
            null
        );

        $this->expectException(ValidationException::class);

        app(MarkEntryPermissionService::class)->create(
            [
                'exam_id' => $draftExam->id,
                'role_name' => 'teacher',
            ],
            $this->school->id
        );
    }
}
