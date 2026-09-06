<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Assessment\AssessmentTypeService;
use App\Services\Assessment\ExamService;
use App\Services\Assessment\MeritListService;
use App\Services\Assessment\ReportCardService;
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
use Tests\Support\Database\PathwayRecommendationBuilder;
use Tests\Support\Database\RoleBuilder;
use Tests\Support\Database\SchoolBuilder;
use Tests\Support\Database\StreamBuilder;
use Tests\Support\Database\TermBuilder;
use Tests\Support\Database\UserBuilder;
use Tests\TestCase;

class ReportCardTest extends TestCase
{
    use DatabaseTransactions;

    private object $school;

    private object $otherSchool;

    private object $user;

    private object $academicYear;

    private object $term;

    private object $juniorGrade;

    private object $primaryGrade;

    private object $juniorStream;

    private object $primaryStream;

    private object $juniorLearner;

    private object $primaryLearner;

    private object $exam;

    private object $otherExam;

    private object $juniorSystem;

    private object $primarySystem;

    private object $juniorScale;

    private object $primaryScale;

    private object $learningAreaOne;

    private object $learningAreaTwo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->school = SchoolBuilder::create();
        $this->otherSchool = SchoolBuilder::create();

        $role = RoleBuilder::create([
            'role_name' => 'Report Processor',
        ]);

        $this->user = UserBuilder::create(
            $this->school,
            $role
        );

        $this->academicYear = AcademicYearBuilder::create(
            $this->school
        );

        $this->term = TermBuilder::create(
            $this->school,
            $this->academicYear
        );

        $juniorLevel = EducationLevelBuilder::create([
            'level_name' => 'Junior School '.Str::random(8),
            'level_order' => 2,
        ]);

        $primaryLevel = EducationLevelBuilder::create([
            'level_name' => 'Primary '.Str::random(8),
            'level_order' => 1,
        ]);

        $this->juniorGrade = GradeBuilder::create(
            $this->school,
            $juniorLevel,
            [
                'grade_name' => 'Junior Grade '.Str::random(8),
                'grade_order' => 7,
            ]
        );

        $this->primaryGrade = GradeBuilder::create(
            $this->school,
            $primaryLevel,
            [
                'grade_name' => 'Primary Grade '.Str::random(8),
                'grade_order' => 6,
            ]
        );

        $this->juniorStream = StreamBuilder::create(
            $this->school,
            $this->juniorGrade,
            ['stream_name' => 'Junior Stream '.Str::random(8)]
        );

        $this->primaryStream = StreamBuilder::create(
            $this->school,
            $this->primaryGrade,
            ['stream_name' => 'Primary Stream '.Str::random(8)]
        );

        $this->juniorLearner = LearnerBuilder::create(
            $this->school,
            $this->juniorGrade,
            $this->juniorStream,
            [
                'first_name' => 'Junior',
                'last_name' => 'Learner',
            ]
        );

        $this->primaryLearner = LearnerBuilder::create(
            $this->school,
            $this->primaryGrade,
            $this->primaryStream,
            [
                'first_name' => 'Primary',
                'last_name' => 'Learner',
            ]
        );

        $this->juniorSystem = GradingSystemBuilder::create(
            $this->school,
            $juniorLevel,
            [
                'grading_name' => 'Junior Grading '.Str::random(8),
                'uses_points' => true,
                'uses_marks' => true,
            ]
        );

        $this->juniorScale = GradingScaleBuilder::create(
            $this->juniorSystem,
            [
                'grade_code' => 'EE',
                'grade_description' => 'Exceeding',
                'min_score' => 0,
                'max_score' => 100,
                'points' => 8,
                'sort_order' => 1,
            ]
        );

        $this->primarySystem = GradingSystemBuilder::create(
            $this->school,
            $primaryLevel,
            [
                'grading_name' => 'Primary Grading '.Str::random(8),
                'uses_points' => false,
                'uses_marks' => true,
            ]
        );

        $this->primaryScale = GradingScaleBuilder::create(
            $this->primarySystem,
            [
                'grade_code' => 'ME',
                'grade_description' => 'Meeting',
                'min_score' => 0,
                'max_score' => 100,
                'points' => null,
                'sort_order' => 1,
            ]
        );

        $assessmentType = app(AssessmentTypeService::class)->create(
            ['assessment_type_name' => 'Report Card Assessment'],
            $this->school->id
        );

        $this->exam = app(ExamService::class)->create(
            [
                'exam_name' => 'Report Card Exam',
                'assessment_type_id' => $assessmentType->id,
                'academic_year_id' => $this->academicYear->id,
                'term_id' => $this->term->id,
                'start_date' => '2026-07-20',
                'end_date' => '2026-07-25',
            ],
            $this->school->id,
            $this->user->id
        );

        $otherAcademicYear = AcademicYearBuilder::create(
            $this->otherSchool
        );

        $otherTerm = TermBuilder::create(
            $this->otherSchool,
            $otherAcademicYear
        );

        $otherAssessmentType = app(AssessmentTypeService::class)->create(
            ['assessment_type_name' => 'Other Report Assessment'],
            $this->otherSchool->id
        );

        $this->otherExam = app(ExamService::class)->create(
            [
                'exam_name' => 'Other School Report Exam',
                'assessment_type_id' => $otherAssessmentType->id,
                'academic_year_id' => $otherAcademicYear->id,
                'term_id' => $otherTerm->id,
                'start_date' => '2026-07-20',
                'end_date' => '2026-07-25',
            ],
            $this->otherSchool->id,
            null
        );

        $this->learningAreaOne = LearningAreaBuilder::create([
            'learning_area_name' => 'Mathematics '.Str::random(8),
        ]);

        $this->learningAreaTwo = LearningAreaBuilder::create([
            'learning_area_name' => 'English '.Str::random(8),
        ]);

        $this->seedResults(
            $this->juniorLearner,
            $this->juniorSystem,
            $this->juniorScale
        );

        $this->seedResults(
            $this->primaryLearner,
            $this->primarySystem,
            $this->primaryScale
        );

        app(MeritListService::class)->generate(
            $this->school->id,
            $this->exam->id,
            null,
            null,
            $this->user->id
        );

        PathwayRecommendationBuilder::create(
            $this->juniorLearner,
            $this->academicYear,
            [
                'recommendation_date' => '2026-03-01',
                'recommended_pathway' => 'STEM',
                'confidence_score' => 90,
            ]
        );
    }

    public function test_generates_card_copies_overall_data_and_details(): void
    {
        $card = $this->generate(
            $this->juniorLearner->id
        )->first();

        $this->assertSame('160.00', $card->overall_score);
        $this->assertSame(16, $card->total_points);
        $this->assertSame(
            'EE',
            $card->overallGradingScale->grade_code
        );
        $this->assertCount(2, $card->learningAreas);
        $this->assertSame(
            8,
            $card->learningAreas->first()->points
        );
        $this->assertNull($card->attendance_percentage);
    }

    public function test_filters_and_junior_only_pathway(): void
    {
        $this->assertCount(
            1,
            $this->generate(
                null,
                $this->juniorGrade->id
            )
        );

        $this->deleteGeneratedCards();

        $junior = $this->generate(
            null,
            null,
            $this->juniorStream->id
        )->first();

        $this->assertSame(
            'STEM',
            $junior->pathway_recommendation
        );

        $this->deleteGeneratedCards();

        $primary = $this->generate(
            $this->primaryLearner->id
        )->first();

        $this->assertNull(
            $primary->pathway_recommendation_id
        );
    }

    public function test_missing_merit_and_results_are_rejected(): void
    {
        DB::table('merit_lists')
            ->where('exam_id', $this->exam->id)
            ->delete();

        $this->expectException(
            ValidationException::class
        );

        $this->generate();
    }

    public function test_missing_learning_areas_is_rejected(): void
    {
        DB::table('learning_area_results')
            ->where('learner_id', $this->juniorLearner->id)
            ->delete();

        $this->expectException(
            ValidationException::class
        );

        $this->generate(
            $this->juniorLearner->id
        );
    }

    public function test_cross_school_is_rejected(): void
    {
        $this->expectException(
            ValidationException::class
        );

        app(ReportCardService::class)->generate(
            $this->school->id,
            $this->otherExam->id,
            null,
            null,
            null,
            $this->user->id
        );
    }

    public function test_upsert_preserves_and_updates_comments_only(): void
    {
        $card = $this->generate(
            $this->juniorLearner->id
        )->first();

        $detail = $card->learningAreas->first();

        app(ReportCardService::class)->updateComments(
            $this->school->id,
            $card->id,
            [
                'class_teacher_comment' => 'Good',
                'principal_comment' => 'Approved',
                'learning_areas' => [
                    [
                        'id' => $detail->id,
                        'teacher_comment' => 'Strong',
                    ],
                ],
            ]
        );

        $again = $this->generate(
            $this->juniorLearner->id
        )->first();

        $this->assertSame(
            $card->id,
            $again->id
        );

        $this->assertSame(
            'Good',
            $again->class_teacher_comment
        );

        $this->assertSame(
            'Strong',
            $again->learningAreas
                ->firstWhere('id', $detail->id)
                ->teacher_comment
        );

        $this->assertDatabaseCount(
            'report_cards',
            1
        );

        $this->assertDatabaseCount(
            'report_card_learning_areas',
            2
        );
    }

    public function test_publishes_generated_cards(): void
    {
        $this->generate(
            $this->juniorLearner->id
        );

        $rows = app(ReportCardService::class)->publish(
            $this->school->id,
            $this->exam->id,
            $this->juniorLearner->id,
            null,
            null,
            $this->user->id
        );

        $this->assertSame(
            'published',
            $rows->first()->status
        );

        $this->assertNotNull(
            $rows->first()->published_at
        );
    }

    public function test_api_output_contains_required_sections(): void
    {
        $this->withoutMiddleware();

        $user = new User;
        $user->forceFill([
            'id' => $this->user->id,
            'school_id' => $this->school->id,
        ]);

        Auth::setUser($user);

        $this->postJson(
            '/api/report-cards/generate',
            [
                'school_id' => $this->school->id,
                'exam_id' => $this->exam->id,
                'learner_id' => $this->juniorLearner->id,
            ]
        )
            ->assertOk()
            ->assertJsonPath(
                'data.0.overall_grade',
                'EE'
            )
            ->assertJsonPath(
                'data.0.total_points',
                16
            )
            ->assertJsonPath(
                'data.0.stream_position',
                1
            )
            ->assertJsonPath(
                'data.0.attendance.percentage',
                null
            )
            ->assertJsonPath(
                'data.0.pathway_recommendation',
                'STEM'
            )
            ->assertJsonCount(
                2,
                'data.0.learning_areas'
            );
    }

    private function seedResults(
        object $learner,
        object $gradingSystem,
        object $gradingScale
    ): void {
        foreach ([
            $this->learningAreaOne,
            $this->learningAreaTwo,
        ] as $learningArea) {
            DB::table('learning_area_results')->insert([
                'id' => (string) Str::uuid(),
                'school_id' => $this->school->id,
                'exam_id' => $this->exam->id,
                'learner_id' => $learner->id,
                'learning_area_id' => $learningArea->id,
                'marks_obtained' => 80,
                'maximum_marks' => 100,
                'percentage' => 80,
                'grading_system_id' => $gradingSystem->id,
                'grading_scale_id' => $gradingScale->id,
                'processing_status' => 'processed',
                'processed_by' => $this->user->id,
                'processed_at' => now(),
                'is_deleted' => false,
            ]);
        }
    }

    private function generate(
        ?string $learner = null,
        ?string $grade = null,
        ?string $stream = null
    ) {
        return app(ReportCardService::class)->generate(
            $this->school->id,
            $this->exam->id,
            $learner,
            $grade,
            $stream,
            $this->user->id
        );
    }

    private function deleteGeneratedCards(): void
    {
        DB::table('report_card_learning_areas')
            ->delete();

        DB::table('report_cards')
            ->delete();
    }
}
