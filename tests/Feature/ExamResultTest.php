<?php

namespace Tests\Feature;

use App\Services\Assessment\AssessmentTypeService;
use App\Services\Assessment\ExamLearningAreaService;
use App\Services\Assessment\ExamPaperService;
use App\Services\Assessment\ExamResultService;
use App\Services\Assessment\ExamService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Support\Database\AcademicYearBuilder;
use Tests\Support\Database\GradeBuilder;
use Tests\Support\Database\LearnerBuilder;
use Tests\Support\Database\LearningAreaBuilder;
use Tests\Support\Database\MarkEntryPermissionBuilder;
use Tests\Support\Database\RoleBuilder;
use Tests\Support\Database\SchoolBuilder;
use Tests\Support\Database\StreamBuilder;
use Tests\Support\Database\TermBuilder;
use Tests\Support\Database\UserBuilder;
use Tests\TestCase;

class ExamResultTest extends TestCase
{
    use DatabaseTransactions;

    private object $school;

    private object $exam;

    private object $learningArea;

    private object $paper;

    private object $learner;

    private object $user;

    private object $permission;

    protected function setUp(): void
    {
        parent::setUp();

        $this->school = SchoolBuilder::create();

        $role = RoleBuilder::create([
            'role_name' => 'Teacher',
        ]);

        $this->user = UserBuilder::create(
            $this->school,
            $role
        );

        $grade = GradeBuilder::create($this->school);
        $stream = StreamBuilder::create($this->school, $grade);

        $this->learner = LearnerBuilder::create(
            $this->school,
            $grade,
            $stream
        );

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
            $this->user->id
        );

        $this->learningArea = LearningAreaBuilder::create();

        $examLearningArea = app(
            ExamLearningAreaService::class
        )->create(
            [
                'exam_id' => $this->exam->id,
                'learning_area_id' => $this->learningArea->id,
                'number_of_papers' => 1,
                'total_marks' => 100,
            ],
            $this->school->id
        );

        $this->paper = app(ExamPaperService::class)->create(
            [
                'exam_learning_area_id' => $examLearningArea->id,
                'paper_name' => 'Paper 1',
                'paper_number' => 1,
                'max_marks' => 100,
            ],
            $this->school->id
        );

        app(ExamService::class)->transition(
            $this->exam,
            'published'
        );

        $this->exam = $this->exam->fresh();

        $this->permission = MarkEntryPermissionBuilder::create(
            $this->exam
        );
    }

    public function test_it_derives_exam_and_learning_area_from_the_paper(): void
    {
        $result = app(ExamResultService::class)->create(
            [
                'learner_id' => $this->learner->id,
                'paper_id' => $this->paper->id,
                'marks' => 78,
            ],
            $this->school->id,
            $this->user->id
        );

        $this->assertSame($this->exam->id, $result->exam_id);
        $this->assertSame(
            $this->learningArea->id,
            $result->learning_area_id
        );
        $this->assertSame('78.00', $result->marks);
    }

    public function test_it_rejects_marks_above_the_paper_maximum(): void
    {
        $this->expectException(ValidationException::class);

        app(ExamResultService::class)->create(
            [
                'learner_id' => $this->learner->id,
                'paper_id' => $this->paper->id,
                'marks' => 101,
            ],
            $this->school->id,
            $this->user->id
        );
    }

    public function test_it_rejects_duplicate_learner_paper_results(): void
    {
        $service = app(ExamResultService::class);

        $data = [
            'learner_id' => $this->learner->id,
            'paper_id' => $this->paper->id,
            'marks' => 70,
        ];

        $service->create(
            $data,
            $this->school->id,
            $this->user->id
        );

        $this->expectException(ValidationException::class);

        $service->create(
            $data,
            $this->school->id,
            $this->user->id
        );
    }

    public function test_it_rejects_cross_school_learners(): void
    {
        $otherSchool = SchoolBuilder::create();

        DB::table('learners')
            ->where('id', $this->learner->id)
            ->update([
                'school_id' => $otherSchool->id,
            ]);

        $this->expectException(ValidationException::class);

        app(ExamResultService::class)->create(
            [
                'learner_id' => $this->learner->id,
                'paper_id' => $this->paper->id,
                'marks' => 65,
            ],
            $this->school->id,
            $this->user->id
        );
    }

    public function test_it_rejects_results_when_the_permission_window_is_closed(): void
    {
        DB::table('mark_entry_permissions')
            ->where('id', $this->permission->id)
            ->update([
                'opens_at' => now()->subHours(2),
                'closes_at' => now()->subHour(),
            ]);

        $this->expectException(ValidationException::class);

        app(ExamResultService::class)->create(
            [
                'learner_id' => $this->learner->id,
                'paper_id' => $this->paper->id,
                'marks' => 65,
            ],
            $this->school->id,
            $this->user->id
        );
    }
}
