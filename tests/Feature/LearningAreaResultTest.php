<?php

namespace Tests\Feature;

use App\Models\TeacherAssignment;
use App\Models\User;
use App\Services\Assessment\AssessmentTypeService;
use App\Services\Assessment\ExamLearningAreaService;
use App\Services\Assessment\ExamPaperService;
use App\Services\Assessment\ExamResultService;
use App\Services\Assessment\ExamService;
use App\Services\Assessment\MeritListService;
use App\Services\Assessment\ReportCardService;
use App\Services\Assessment\ResultProcessingService;
use App\Services\TeacherPortal\MarkEntryBatchService;
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
use Tests\Support\Database\MarkEntryPermissionBuilder;
use Tests\Support\Database\RoleBuilder;
use Tests\Support\Database\SchoolBuilder;
use Tests\Support\Database\StreamBuilder;
use Tests\Support\Database\TeacherBuilder;
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

    public function test_mark_change_requires_reprocessing_and_output_regeneration(): void
    {
        $this->prepareFreshnessOutputs();

        app(ExamResultService::class)->updateMarks(
            $this->school->id,
            $this->freshnessRawId($this->juniorLearner),
            45,
            $this->user->id
        );

        $this->assertDatabaseHas('learning_area_results', [
            'exam_id' => $this->exam->id,
            'learner_id' => $this->juniorLearner->id,
            'processing_status' => 'stale',
        ]);
        $this->assertDatabaseHas('learning_area_results', [
            'exam_id' => $this->exam->id,
            'learner_id' => $this->primaryLearner->id,
            'processing_status' => 'processed',
        ]);

        foreach (['merit_lists', 'report_cards'] as $table) {
            $this->assertSame(2, DB::table($table)
                ->where('exam_id', $this->exam->id)
                ->where('status', 'stale')->count());
        }

        $before = $this->freshnessSnapshot();

        $this->assertFreshnessRejected(fn () => $this->generateFreshnessMerits());
        $this->assertFreshnessRejected(fn () => $this->generateFreshnessCards());
        $this->assertFreshnessRejected(fn () => app(MeritListService::class)
            ->publish($this->school->id, $this->exam->id, null, null));
        $this->assertFreshnessRejected(fn () => app(ReportCardService::class)
            ->publish($this->school->id, $this->exam->id, null, null, null, $this->user->id));
        $this->assertSame($before, $this->freshnessSnapshot());

        $processed = $this->process($this->juniorLearner);
        $this->assertSame('80.00', $processed->percentage);
        $this->assertSame('processed', $processed->processing_status);

        $this->generateFreshnessMerits();

        // Reprocessing and merit generation do not refresh stored report cards.
        $this->assertFreshnessRejected(fn () => app(ReportCardService::class)
            ->publish($this->school->id, $this->exam->id, null, null, null, $this->user->id));

        $cards = $this->generateFreshnessCards();
        $this->assertSame('80.00', $cards->firstWhere('learner_id', $this->juniorLearner->id)->average_percentage);

        app(MeritListService::class)
            ->publish($this->school->id, $this->exam->id, null, null);
        app(ReportCardService::class)
            ->publish($this->school->id, $this->exam->id, null, null, null, $this->user->id);

        foreach (['merit_lists', 'report_cards'] as $table) {
            $this->assertSame(2, DB::table($table)
                ->where('exam_id', $this->exam->id)
                ->where('status', 'published')->count());
        }
    }

    public function test_deleted_paper_keeps_outputs_stale_until_the_paper_is_restored(): void
    {
        $this->prepareFreshnessOutputs();

        app(ExamResultService::class)->deleteResult(
            $this->school->id,
            $this->freshnessRawId($this->juniorLearner),
            $this->user->id
        );

        $before = $this->freshnessSnapshot();
        $this->assertFreshnessRejected(fn () => $this->process($this->juniorLearner));
        $this->assertFreshnessRejected(fn () => $this->generateFreshnessMerits());
        $this->assertSame($before, $this->freshnessSnapshot());

        app(ExamResultService::class)->create([
            'learner_id' => $this->juniorLearner->id,
            'paper_id' => $this->paperOne->id,
            'marks' => 45,
        ], $this->school->id, $this->user->id);

        $this->assertFreshnessRejected(fn () => $this->generateFreshnessMerits());
        $this->assertSame('80.00', $this->process($this->juniorLearner)->percentage);
        $this->generateFreshnessMerits();
        $this->assertCount(2, $this->generateFreshnessCards());
    }

    public function test_published_card_freezes_source_changes_across_the_exam(): void
    {
        $this->prepareFreshnessOutputs();

        app(ReportCardService::class)->publish(
            $this->school->id,
            $this->exam->id,
            $this->juniorLearner->id,
            null,
            null,
            $this->user->id
        );

        $before = $this->freshnessSnapshot();
        $service = app(ExamResultService::class);
        $rawId = $this->freshnessRawId($this->primaryLearner);

        $this->assertFreshnessRejected(fn () => $service->updateMarks(
            $this->school->id, $rawId, 45, $this->user->id
        ), 'results');
        $this->assertFreshnessRejected(fn () => $service->deleteResult(
            $this->school->id, $rawId, $this->user->id
        ), 'results');
        $this->assertFreshnessRejected(fn () => $this->process($this->primaryLearner), 'results');
        $this->assertFreshnessRejected(fn () => $service->create([
            'learner_id' => $this->primaryLearner->id,
            'paper_id' => $this->paperOne->id,
            'marks' => 45,
        ], $this->school->id, $this->user->id), 'results');

        // Its merit row is still generated, but the published card protects it.
        $this->assertFreshnessRejected(fn () => $this->generateFreshnessMerits(), 'report_cards');
        $this->assertSame($before, $this->freshnessSnapshot());
    }

    public function test_restoring_a_deleted_paper_preserves_its_identity(): void
    {
        $this->prepareFreshnessOutputs();

        $rawId = $this->freshnessRawId($this->juniorLearner);
        DB::table('exam_results')->where('id', $rawId)->update([
            'created_at' => now()->subDay(),
        ]);
        $createdAt = DB::table('exam_results')->where('id', $rawId)->value('created_at');

        $service = app(ExamResultService::class);
        $service->deleteResult($this->school->id, $rawId, $this->user->id);

        $restored = $service->create([
            'learner_id' => $this->juniorLearner->id,
            'paper_id' => $this->paperOne->id,
            'marks' => 45,
        ], $this->school->id, $this->user->id);

        $this->assertSame($rawId, $restored->id);
        $this->assertSame($createdAt, DB::table('exam_results')->where('id', $rawId)->value('created_at'));
        $this->assertSame('45.00', $restored->marks);
        $this->assertFalse($restored->is_deleted);
        $this->assertNull($restored->deleted_at);
        $this->assertNull($restored->deleted_by);
        $this->assertSame($this->user->id, $restored->entered_by);

        $this->assertSame(1, DB::table('exam_results')
            ->where('exam_id', $this->exam->id)
            ->where('learner_id', $this->juniorLearner->id)
            ->where('paper_id', $this->paperOne->id)
            ->count());

        $this->assertDatabaseHas('learning_area_results', [
            'exam_id' => $this->exam->id,
            'learner_id' => $this->juniorLearner->id,
            'processing_status' => 'stale',
        ]);
    }

    private function prepareFreshnessOutputs(): void
    {
        app(ExamService::class)->transition($this->exam, 'published');
        $this->exam = $this->exam->fresh();

        $permission = MarkEntryPermissionBuilder::create($this->exam);
        $roleName = DB::table('users')
            ->join('roles', 'roles.id', '=', 'users.role_id')
            ->where('users.id', $this->user->id)
            ->value('roles.role_name');

        DB::table('mark_entry_permissions')->where('id', $permission->id)->update([
            'role_name' => $roleName,
            'active' => true,
            'opens_at' => now()->subHour(),
            'closes_at' => now()->addDay(),
        ]);

        $this->process($this->juniorLearner);
        $this->process($this->primaryLearner);
        $this->generateFreshnessMerits();
        $this->generateFreshnessCards();
    }

    private function generateFreshnessMerits()
    {
        return app(MeritListService::class)->generate(
            $this->school->id, $this->exam->id, null, null, $this->user->id
        );
    }

    private function generateFreshnessCards()
    {
        return app(ReportCardService::class)->generate(
            $this->school->id, $this->exam->id, null, null, null, $this->user->id
        );
    }

    private function freshnessRawId(object $learner): string
    {
        return DB::table('exam_results')
            ->where('exam_id', $this->exam->id)
            ->where('learner_id', $learner->id)
            ->where('paper_id', $this->paperOne->id)
            ->where('is_deleted', false)
            ->value('id');
    }

    private function assertFreshnessRejected(callable $operation, ?string $key = null): void
    {
        try {
            $operation();
        } catch (ValidationException $exception) {
            $this->assertNotEmpty($exception->errors());
            if ($key !== null) {
                $this->assertArrayHasKey($key, $exception->errors());
            }

            return;
        }

        $this->fail('Expected the lifecycle operation to be rejected.');
    }

    private function freshnessSnapshot(): array
    {
        $snapshot = [];

        foreach (['exam_results', 'learning_area_results', 'merit_lists', 'report_cards'] as $table) {
            $snapshot[$table] = DB::table($table)
                ->where('exam_id', $this->exam->id)
                ->orderBy('id')->get()
                ->map(fn ($row) => (array) $row)->all();
        }

        return $snapshot;
    }

    public function test_teacher_correction_invalidates_processed_results_and_outputs(): void
    {
        $this->prepareFreshnessOutputs();
        [$teacher, $assignment] = $this->freshnessTeacherAssignment();

        $service = app(MarkEntryBatchService::class);
        $batch = $service->save($teacher, $this->paperOne->id, [
            ['learner_id' => $this->juniorLearner->id, 'marks' => 45],
        ], $assignment->id);

        $item = $batch->items->first();
        $item->update([
            'exam_result_id' => $this->freshnessRawId($this->juniorLearner),
        ]);
        $batch->update(['status' => 'changes_requested']);

        $submitted = $service->submit($teacher, $batch->id);

        $this->assertSame('submitted', $submitted->status);
        $this->assertSame(40.0, (float) $submitted->items->first()->previous_marks);
        $this->assertDatabaseHas('exam_results', [
            'id' => $item->exam_result_id,
            'marks' => 45,
        ]);
        $this->assertDatabaseHas('learning_area_results', [
            'exam_id' => $this->exam->id,
            'learner_id' => $this->juniorLearner->id,
            'processing_status' => 'stale',
        ]);
        $this->assertSame(2, DB::table('merit_lists')
            ->where('exam_id', $this->exam->id)
            ->where('status', 'stale')->count());
        $this->assertSame(2, DB::table('report_cards')
            ->where('exam_id', $this->exam->id)
            ->where('status', 'stale')->count());

        $this->assertFreshnessRejected(fn () => $this->generateFreshnessMerits(), 'results');
    }

    public function test_published_card_blocks_teacher_batch_save_and_submission(): void
    {
        $this->prepareFreshnessOutputs();
        [$teacher, $assignment] = $this->freshnessTeacherAssignment();

        $service = app(MarkEntryBatchService::class);
        $batch = $service->save($teacher, $this->paperOne->id, [
            ['learner_id' => $this->juniorLearner->id, 'marks' => 45],
        ], $assignment->id);

        $batch->items->first()->update([
            'exam_result_id' => $this->freshnessRawId($this->juniorLearner),
        ]);
        $batch->update(['status' => 'reopened']);

        app(ReportCardService::class)->publish(
            $this->school->id, $this->exam->id, $this->juniorLearner->id,
            null, null, $this->user->id
        );

        $before = $this->freshnessSnapshot();
        $batchBefore = (array) DB::table('mark_entry_batches')->where('id', $batch->id)->first();
        $itemsBefore = DB::table('mark_entry_batch_items')
            ->where('batch_id', $batch->id)->orderBy('id')
            ->get()->map(fn ($row) => (array) $row)->all();

        $this->assertFreshnessRejected(fn () => $service->save(
            $teacher, $this->paperOne->id, [
                ['learner_id' => $this->juniorLearner->id, 'marks' => 46],
            ], $assignment->id
        ), 'results');
        $this->assertFreshnessRejected(fn () => $service->submit($teacher, $batch->id), 'results');

        $this->assertSame($before, $this->freshnessSnapshot());
        $this->assertSame($batchBefore, (array) DB::table('mark_entry_batches')
            ->where('id', $batch->id)->first());
        $this->assertSame($itemsBefore, DB::table('mark_entry_batch_items')
            ->where('batch_id', $batch->id)->orderBy('id')
            ->get()->map(fn ($row) => (array) $row)->all());
    }

    private function freshnessTeacherAssignment(): array
    {
        DB::table('academic_years')->where('id', $this->exam->academic_year_id)->update([
            'start_date' => now()->startOfYear()->toDateString(),
            'end_date' => now()->endOfYear()->toDateString(),
            'active' => true,
        ]);
        DB::table('terms')->where('id', $this->exam->term_id)->update([
            'start_date' => now()->subDay()->toDateString(),
            'end_date' => now()->addDay()->toDateString(),
            'active' => true,
        ]);

        $role = RoleBuilder::create(['role_name' => 'Teacher']);
        $record = UserBuilder::create($this->school, $role);
        $profile = TeacherBuilder::create($this->school, $record);
        $teacher = User::withoutGlobalScopes()->findOrFail($record->id);

        $assignment = TeacherAssignment::create([
            'id' => (string) Str::uuid(),
            'school_id' => $this->school->id,
            'teacher_id' => $profile->id,
            'learning_area_id' => $this->learningArea->id,
            'grade_id' => $this->juniorLearner->grade_id,
            'stream_id' => $this->juniorLearner->stream_id,
            'academic_year_id' => $this->exam->academic_year_id,
            'term_id' => $this->exam->term_id,
            'is_class_teacher' => false,
            'lessons_per_week' => 5,
            'active' => true,
            'is_deleted' => false,
            'created_at' => now(),
        ]);

        return [$teacher, $assignment];
    }

    public function test_exam_transition_checks_stored_status_instead_of_a_stale_model(): void
    {
        $staleDraft = clone $this->exam;
        $service = app(ExamService::class);

        $service->transition($this->exam, 'published');
        $this->assertSame('published', $this->exam->status);

        $service->transition($this->exam, 'closed');
        $this->assertSame('closed', $this->exam->status);

        $before = $this->freshnessSnapshot();

        $this->assertFreshnessRejected(
            fn () => $service->transition($staleDraft, 'published'),
            'status'
        );
        $this->assertSame('closed', $this->exam->fresh()->status);
        $this->assertSame($before, $this->freshnessSnapshot());

        $this->assertFreshnessRejected(
            fn () => $this->process($this->juniorLearner),
            'exam_id'
        );
        $this->assertSame($before, $this->freshnessSnapshot());
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
