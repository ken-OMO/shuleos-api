<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Assessment\AssessmentTypeService;
use App\Services\Assessment\ExamLearningAreaService;
use App\Services\Assessment\ExamPaperService;
use App\Services\Assessment\ExamService;
use App\Services\Assessment\ResultProcessingService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\Database\AcademicYearBuilder;
use Tests\Support\Database\EducationLevelBuilder;
use Tests\Support\Database\GradeBuilder;
use Tests\Support\Database\GradingScaleBuilder;
use Tests\Support\Database\GradingSystemBuilder;
use Tests\Support\Database\LearnerBuilder;
use Tests\Support\Database\LearningAreaBuilder;
use Tests\Support\Database\RoleBuilder;
use Tests\Support\Database\SchoolBuilder;
use Tests\Support\Database\StreamBuilder;
use Tests\Support\Database\TermBuilder;
use Tests\Support\Database\UserBuilder;
use Tests\TestCase;

class LearningAreaResultTest extends TestCase
{
    use DatabaseTransactions;

    private object $school;

    private object $otherSchool;

    private object $user;

    private object $juniorLearner;

    private object $primaryLearner;

    private object $exam;

    private object $learningArea;

    private object $examLearningArea;

    private object $paperOne;

    private object $paperTwo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->school = SchoolBuilder::create();
        $this->otherSchool = SchoolBuilder::create();

        $role = RoleBuilder::create([
            'role_name' => 'Results Processor',
        ]);

        $this->user = UserBuilder::create(
            $this->school,
            $role
        );

        $juniorLevel = EducationLevelBuilder::create([
            'level_name' => 'Junior Secondary '.Str::random(8),
            'level_order' => 2,
        ]);

        $primaryLevel = EducationLevelBuilder::create([
            'level_name' => 'Primary '.Str::random(8),
            'level_order' => 1,
        ]);

        $juniorGrade = GradeBuilder::create(
            $this->school,
            $juniorLevel,
            ['grade_name' => 'Junior Grade '.Str::random(8)]
        );

        $primaryGrade = GradeBuilder::create(
            $this->school,
            $primaryLevel,
            ['grade_name' => 'Primary Grade '.Str::random(8)]
        );

        $juniorStream = StreamBuilder::create(
            $this->school,
            $juniorGrade
        );

        $primaryStream = StreamBuilder::create(
            $this->school,
            $primaryGrade
        );

        $this->juniorLearner = LearnerBuilder::create(
            $this->school,
            $juniorGrade,
            $juniorStream
        );

        $this->primaryLearner = LearnerBuilder::create(
            $this->school,
            $primaryGrade,
            $primaryStream
        );

        $juniorSystem = GradingSystemBuilder::create(
            $this->school,
            $juniorLevel,
            [
                'grading_name' => 'Junior Grading '.Str::random(8),
                'uses_points' => true,
            ]
        );

        GradingScaleBuilder::create($juniorSystem, [
            'grade_code' => 'EE2',
            'grade_description' => 'Exceeding Expectation',
            'min_score' => 75,
            'max_score' => 100,
            'points' => 7,
            'sort_order' => 1,
        ]);

        GradingScaleBuilder::create($juniorSystem, [
            'grade_code' => 'ME',
            'grade_description' => 'Meeting Expectation',
            'min_score' => 0,
            'max_score' => 74.99,
            'points' => null,
            'sort_order' => 2,
        ]);

        $primarySystem = GradingSystemBuilder::create(
            $this->school,
            $primaryLevel,
            [
                'grading_name' => 'Primary Grading '.Str::random(8),
                'uses_points' => false,
            ]
        );

        GradingScaleBuilder::create($primarySystem, [
            'grade_code' => 'EE',
            'grade_description' => 'Exceeding Expectation',
            'min_score' => 75,
            'max_score' => 100,
            'points' => null,
            'sort_order' => 1,
        ]);

        GradingScaleBuilder::create($primarySystem, [
            'grade_code' => 'ME',
            'grade_description' => 'Meeting Expectation',
            'min_score' => 0,
            'max_score' => 74.99,
            'points' => null,
            'sort_order' => 2,
        ]);

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

        $this->learningArea = LearningAreaBuilder::create([
            'learning_area_name' => 'Mathematics '.Str::random(8),
        ]);

        $this->examLearningArea = app(
            ExamLearningAreaService::class
        )->create(
            [
                'exam_id' => $this->exam->id,
                'learning_area_id' => $this->learningArea->id,
                'number_of_papers' => 2,
                'total_marks' => 100,
            ],
            $this->school->id
        );

        $this->paperOne = app(ExamPaperService::class)->create(
            [
                'exam_learning_area_id' => $this->examLearningArea->id,
                'paper_name' => 'Paper 1',
                'paper_number' => 1,
                'max_marks' => 50,
            ],
            $this->school->id
        );

        $this->paperTwo = app(ExamPaperService::class)->create(
            [
                'exam_learning_area_id' => $this->examLearningArea->id,
                'paper_name' => 'Paper 2',
                'paper_number' => 2,
                'max_marks' => 50,
            ],
            $this->school->id
        );

        $this->insertRawResults($this->juniorLearner, 40, 35);
        $this->insertRawResults($this->primaryLearner, 40, 35);
    }

    public function test_it_aggregates_two_papers_and_applies_junior_grade_and_points(): void
    {
        $result = $this->process($this->juniorLearner);

        $this->assertSame('75.00', $result->marks_obtained);
        $this->assertSame('100.00', $result->maximum_marks);
        $this->assertSame('75.00', $result->percentage);
        $this->assertSame('EE2', $result->gradingScale->grade_code);
        $this->assertSame(7, $result->gradingScale->points);
    }

    public function test_primary_grade_has_null_points(): void
    {
        $result = $this->process($this->primaryLearner);

        $this->assertSame('EE', $result->gradingScale->grade_code);
        $this->assertNull($result->gradingScale->points);
    }

    public function test_missing_paper_result_is_rejected(): void
    {
        DB::table('exam_results')
            ->where('learner_id', $this->juniorLearner->id)
            ->where('paper_id', $this->paperTwo->id)
            ->delete();

        $this->expectException(ValidationException::class);

        $this->process($this->juniorLearner);
    }

    public function test_cross_school_is_rejected(): void
    {
        DB::table('learners')
            ->where('id', $this->juniorLearner->id)
            ->update(['school_id' => $this->otherSchool->id]);

        $this->expectException(ValidationException::class);

        $this->process($this->juniorLearner);
    }

    public function test_inconsistent_total_marks_is_rejected(): void
    {
        DB::table('exam_learning_areas')
            ->where('id', $this->examLearningArea->id)
            ->update(['total_marks' => 90]);

        $this->expectException(ValidationException::class);

        $this->process($this->juniorLearner);
    }

    public function test_processing_upserts_the_same_result(): void
    {
        $first = $this->process($this->juniorLearner);

        DB::table('exam_results')
            ->where('learner_id', $this->juniorLearner->id)
            ->where('paper_id', $this->paperOne->id)
            ->update(['marks' => 45]);

        $second = $this->process($this->juniorLearner);

        $this->assertSame($first->id, $second->id);
        $this->assertSame('80.00', $second->percentage);
        $this->assertDatabaseCount('learning_area_results', 1);
    }

    public function test_api_response_contains_grade_and_points(): void
    {
        $this->withoutMiddleware();

        $user = new User;
        $user->forceFill([
            'id' => $this->user->id,
            'school_id' => $this->school->id,
        ]);

        Auth::setUser($user);

        $this->postJson(
            '/api/learning-area-results/process',
            [
                'school_id' => $this->school->id,
                'exam_learning_area_id' => $this->examLearningArea->id,
                'learner_id' => $this->juniorLearner->id,
            ]
        )
            ->assertOk()
            ->assertJsonPath('data.grade_code', 'EE2')
            ->assertJsonPath('data.points', 7);
    }

    private function insertRawResults(
        object $learner,
        float $first,
        float $second
    ): void {
        foreach ([
            [$this->paperOne->id, $first],
            [$this->paperTwo->id, $second],
        ] as [$paperId, $marks]) {
            DB::table('exam_results')->insert([
                'id' => (string) Str::uuid(),
                'exam_id' => $this->exam->id,
                'learner_id' => $learner->id,
                'learning_area_id' => $this->learningArea->id,
                'paper_id' => $paperId,
                'marks' => $marks,
                'is_deleted' => false,
            ]);
        }
    }

    private function process(object $learner)
    {
        return app(ResultProcessingService::class)->process(
            $this->school->id,
            $this->examLearningArea->id,
            $learner->id,
            $this->user->id
        );
    }
}
