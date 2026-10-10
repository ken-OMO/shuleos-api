<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Assessment\AssessmentTypeService;
use App\Services\Assessment\ExamService;
use App\Services\Assessment\MeritListService;
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

class MeritListTest extends TestCase
{
    use DatabaseTransactions;

    private object $school;

    private object $otherSchool;

    private object $user;

    private object $gradeOne;

    private object $gradeTwo;

    private object $streamOne;

    private object $streamTwo;

    private object $streamThree;

    private object $learnerA;

    private object $learnerB;

    private object $learnerC;

    private object $learnerD;

    private object $exam;

    private object $otherExam;

    private object $gradingSystem;

    private object $highScale;

    private object $midScale;

    private object $learningAreaOne;

    private object $learningAreaTwo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->school = SchoolBuilder::create();
        $this->otherSchool = SchoolBuilder::create();

        $role = RoleBuilder::create([
            'role_name' => 'Merit Processor',
        ]);

        $this->user = UserBuilder::create(
            $this->school,
            $role
        );

        $educationLevel = EducationLevelBuilder::create([
            'level_name' => 'Junior Secondary '.Str::random(8),
            'level_order' => 2,
        ]);

        $this->gradeOne = GradeBuilder::create(
            $this->school,
            $educationLevel,
            [
                'grade_name' => 'Grade One '.Str::random(8),
                'grade_order' => 1,
            ]
        );

        $this->gradeTwo = GradeBuilder::create(
            $this->school,
            $educationLevel,
            [
                'grade_name' => 'Grade Two '.Str::random(8),
                'grade_order' => 2,
            ]
        );

        $this->streamOne = StreamBuilder::create(
            $this->school,
            $this->gradeOne,
            ['stream_name' => 'Stream A '.Str::random(8)]
        );

        $this->streamTwo = StreamBuilder::create(
            $this->school,
            $this->gradeOne,
            ['stream_name' => 'Stream B '.Str::random(8)]
        );

        $this->streamThree = StreamBuilder::create(
            $this->school,
            $this->gradeTwo,
            ['stream_name' => 'Stream C '.Str::random(8)]
        );

        $this->learnerA = LearnerBuilder::create(
            $this->school,
            $this->gradeOne,
            $this->streamOne,
            ['first_name' => 'Learner', 'last_name' => 'A']
        );

        $this->learnerB = LearnerBuilder::create(
            $this->school,
            $this->gradeOne,
            $this->streamOne,
            ['first_name' => 'Learner', 'last_name' => 'B']
        );

        $this->learnerC = LearnerBuilder::create(
            $this->school,
            $this->gradeOne,
            $this->streamTwo,
            ['first_name' => 'Learner', 'last_name' => 'C']
        );

        $this->learnerD = LearnerBuilder::create(
            $this->school,
            $this->gradeTwo,
            $this->streamThree,
            ['first_name' => 'Learner', 'last_name' => 'D']
        );

        $this->gradingSystem = GradingSystemBuilder::create(
            $this->school,
            $educationLevel,
            [
                'grading_name' => 'Junior Grading '.Str::random(8),
                'uses_points' => true,
                'uses_marks' => true,
            ]
        );

        $this->highScale = GradingScaleBuilder::create(
            $this->gradingSystem,
            [
                'grade_code' => 'EE',
                'grade_description' => 'Exceeding',
                'min_score' => 75,
                'max_score' => 100,
                'points' => 8,
                'sort_order' => 1,
            ]
        );

        $this->midScale = GradingScaleBuilder::create(
            $this->gradingSystem,
            [
                'grade_code' => 'ME',
                'grade_description' => 'Meeting',
                'min_score' => 0,
                'max_score' => 74.99,
                'points' => 6,
                'sort_order' => 2,
            ]
        );

        $assessmentType = app(AssessmentTypeService::class)->create(
            ['assessment_type_name' => 'Merit Assessment'],
            $this->school->id
        );

        $academicYear = AcademicYearBuilder::create($this->school);
        $term = TermBuilder::create($this->school, $academicYear);

        $this->exam = app(ExamService::class)->create(
            [
                'exam_name' => 'Merit Ranking Exam',
                'assessment_type_id' => $assessmentType->id,
                'academic_year_id' => $academicYear->id,
                'term_id' => $term->id,
                'start_date' => '2026-07-20',
                'end_date' => '2026-07-25',
            ],
            $this->school->id,
            $this->user->id
        );

        $otherAssessmentType = app(AssessmentTypeService::class)->create(
            ['assessment_type_name' => 'Other School Assessment'],
            $this->otherSchool->id
        );

        $otherAcademicYear = AcademicYearBuilder::create(
            $this->otherSchool,
            ['year_name' => '2026 Other']
        );

        $otherTerm = TermBuilder::create(
            $this->otherSchool,
            $otherAcademicYear,
            ['term_name' => 'Other Term']
        );

        $this->otherExam = app(ExamService::class)->create(
            [
                'exam_name' => 'Other School Exam',
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
            'learning_area_name' => 'Science '.Str::random(8),
        ]);

        $this->results($this->learnerA, 90, $this->highScale);
        $this->results($this->learnerB, 80, $this->highScale);
        $this->results($this->learnerC, 80, $this->highScale);
        $this->results($this->learnerD, 70, $this->midScale);
    }

    public function test_totals_averages_junior_grade_and_points(): void
    {
        $row = $this->generate()
            ->firstWhere('learner_id', $this->learnerA->id);

        $this->assertSame('180.00', $row->total_score);
        $this->assertSame('200.00', $row->maximum_marks);
        $this->assertSame('90.00', $row->average_percentage);
        $this->assertSame(16, $row->total_points);
        $this->assertSame('EE', $row->overallGradingScale->grade_code);
    }

    public function test_competition_ties_and_all_positions(): void
    {
        $rows = $this->generate()->keyBy('learner_id');

        $this->assertSame(
            [1, 2, 2, 4],
            [
                $rows[$this->learnerA->id]->school_position,
                $rows[$this->learnerB->id]->school_position,
                $rows[$this->learnerC->id]->school_position,
                $rows[$this->learnerD->id]->school_position,
            ]
        );

        $this->assertSame(
            1,
            $rows[$this->learnerC->id]->stream_position
        );

        $this->assertSame(
            2,
            $rows[$this->learnerC->id]->grade_position
        );
    }

    public function test_grade_and_stream_filters(): void
    {
        $this->assertCount(
            3,
            $this->generate($this->gradeOne->id)
        );

        DB::table('merit_lists')
            ->where('exam_id', $this->exam->id)
            ->delete();

        $this->assertCount(
            2,
            $this->generate(null, $this->streamOne->id)
        );
    }

    public function test_cross_school_exam_is_rejected(): void
    {
        $this->expectException(ValidationException::class);

        app(MeritListService::class)->generate(
            $this->school->id,
            $this->otherExam->id,
            null,
            null,
            $this->user->id
        );
    }

    public function test_no_processed_results_is_rejected(): void
    {
        DB::table('learning_area_results')
            ->where('exam_id', $this->exam->id)
            ->delete();

        $this->expectException(ValidationException::class);

        $this->generate();
    }

    public function test_generation_upserts_rows(): void
    {
        $first = $this->generate()->first()->id;

        $this->generate();

        $this->assertSame(
            $first,
            DB::table('merit_lists')
                ->where('id', $first)
                ->value('id')
        );

        $this->assertDatabaseCount('merit_lists', 4);
    }

    public function test_publishing_updates_matching_rows(): void
    {
        $this->generate($this->gradeOne->id);

        $rows = app(MeritListService::class)->publish(
            $this->school->id,
            $this->exam->id,
            $this->gradeOne->id,
            null
        );

        $this->assertCount(3, $rows);

        $this->assertTrue(
            $rows->every(
                fn ($row) => $row->status === 'published'
                    && $row->published_at !== null
            )
        );
    }

    public function test_api_resource_exposes_rank_and_grade_output(): void
    {
        $this->withoutMiddleware();

        $user = new User;
        $user->forceFill([
            'id' => $this->user->id,
            'school_id' => $this->school->id,
        ]);

        Auth::setUser($user);

        $this->postJson(
            '/api/merit-lists/generate',
            [
                'school_id' => $this->school->id,
                'exam_id' => $this->exam->id,
                'stream_id' => $this->streamOne->id,
            ]
        )
            ->assertOk()
            ->assertJsonPath('data.0.overall_grade', 'EE')
            ->assertJsonPath('data.0.overall_points', 8)
            ->assertJsonPath('data.0.stream_position', 1);
    }

    public function test_published_merit_lists_cannot_be_regenerated(): void
    {
        $this->generate();
        $this->publishSelection();

        $before = $this->meritSnapshot();

        $this->assertPublishedGenerationRejected();
        $this->assertSame($before, $this->meritSnapshot());
    }

    public function test_mixed_generation_is_rejected_without_updating_or_creating_rows(): void
    {
        $this->generate($this->gradeOne->id);
        $this->publishSelection(null, $this->streamTwo->id);

        DB::table('learning_area_results')
            ->where('exam_id', $this->exam->id)
            ->where('learner_id', $this->learnerA->id)
            ->update(['marks_obtained' => 60, 'percentage' => 60]);

        $before = $this->meritSnapshot();

        $this->assertPublishedGenerationRejected();

        $this->assertSame($before, $this->meritSnapshot());
        $this->assertDatabaseMissing('merit_lists', [
            'exam_id' => $this->exam->id,
            'learner_id' => $this->learnerD->id,
        ]);
    }

    public function test_published_rows_outside_selection_do_not_block_generation(): void
    {
        $this->generate($this->gradeOne->id);
        $this->publishSelection($this->gradeOne->id);

        $before = $this->meritSnapshot();

        $rows = $this->generate($this->gradeTwo->id);

        $this->assertCount(1, $rows);
        $this->assertSame($this->learnerD->id, $rows->first()->learner_id);
        $this->assertSame('generated', $rows->first()->status);

        $after = $this->meritSnapshot();
        foreach ($before as $id => $row) {
            $this->assertSame($row, $after[$id]);
        }
    }

    public function test_published_learner_moved_into_selection_cannot_be_overwritten(): void
    {
        $this->generate();
        $this->publishSelection(null, $this->streamTwo->id);

        DB::table('learners')
            ->where('id', $this->learnerC->id)
            ->update(['stream_id' => $this->streamOne->id]);

        $before = $this->meritSnapshot();

        $this->assertPublishedGenerationRejected(null, $this->streamOne->id);
        $this->assertSame($before, $this->meritSnapshot());
    }

    public function test_published_row_stored_in_selection_blocks_after_learner_moves_out(): void
    {
        $this->generate();
        $this->publishSelection(null, $this->streamTwo->id);

        DB::table('learners')
            ->where('id', $this->learnerC->id)
            ->update(['stream_id' => $this->streamOne->id]);

        DB::table('learners')
            ->where('id', $this->learnerB->id)
            ->update(['stream_id' => $this->streamTwo->id]);

        $before = $this->meritSnapshot();

        $this->assertPublishedGenerationRejected(null, $this->streamTwo->id);
        $this->assertSame($before, $this->meritSnapshot());
    }

    public function test_deleted_published_merit_row_cannot_be_regenerated(): void
    {
        $this->generate();
        $this->publishSelection(null, $this->streamTwo->id);

        DB::table('merit_lists')
            ->where('exam_id', $this->exam->id)
            ->where('learner_id', $this->learnerC->id)
            ->update([
                'is_deleted' => true,
                'deleted_at' => now(),
                'deleted_by' => $this->user->id,
            ]);

        $before = $this->meritSnapshot();

        $this->assertPublishedGenerationRejected($this->gradeOne->id);
        $this->assertSame($before, $this->meritSnapshot());
    }

    public function test_api_rejects_regeneration_of_published_merit_lists(): void
    {
        $this->generate();
        $this->publishSelection();
        $before = $this->meritSnapshot();

        $this->withoutMiddleware();

        $user = new User;
        $user->forceFill([
            'id' => $this->user->id,
            'school_id' => $this->school->id,
        ]);
        Auth::setUser($user);

        $this->postJson('/api/merit-lists/generate', [
            'school_id' => $this->school->id,
            'exam_id' => $this->exam->id,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('merit_lists');

        $this->assertSame($before, $this->meritSnapshot());
    }

    private function publishSelection(?string $grade = null, ?string $stream = null): void
    {
        app(MeritListService::class)->publish(
            $this->school->id,
            $this->exam->id,
            $grade,
            $stream
        );
    }

    private function assertPublishedGenerationRejected(?string $grade = null, ?string $stream = null): void
    {
        try {
            $this->generate($grade, $stream);
        } catch (ValidationException $exception) {
            $this->assertSame([
                'merit_lists' => ['Published merit lists cannot be regenerated.'],
            ], $exception->errors());

            return;
        }

        $this->fail('Expected published merit-list regeneration to be rejected.');
    }

    private function meritSnapshot(): array
    {
        return DB::table('merit_lists')
            ->where('school_id', $this->school->id)
            ->where('exam_id', $this->exam->id)
            ->orderBy('id')
            ->get()
            ->mapWithKeys(fn ($row) => [$row->id => (array) $row])
            ->all();
    }

    private function results(
        object $learner,
        int $percentage,
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
                'marks_obtained' => $percentage,
                'maximum_marks' => 100,
                'percentage' => $percentage,
                'grading_system_id' => $this->gradingSystem->id,
                'grading_scale_id' => $gradingScale->id,
                'processing_status' => 'processed',
                'processed_by' => $this->user->id,
                'processed_at' => now(),
                'is_deleted' => false,
            ]);
        }
    }

    private function generate(
        ?string $grade = null,
        ?string $stream = null
    ) {
        return app(MeritListService::class)->generate(
            $this->school->id,
            $this->exam->id,
            $grade,
            $stream,
            $this->user->id
        );
    }
}
