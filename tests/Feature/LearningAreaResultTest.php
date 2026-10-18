<?php

namespace Tests\Feature;

use App\Http\Resources\ReportCardLearningAreaResource;
use App\Http\Resources\ReportCardResource;
use App\Models\TeacherAssignment;
use App\Models\User;
use App\Services\Assessment\AssessmentTypeService;
use App\Services\Assessment\ExamLearningAreaService;
use App\Services\Assessment\ExamPaperService;
use App\Services\Assessment\ExamResultService;
use App\Services\Assessment\ExamRosterService;
use App\Services\Assessment\ExamService;
use App\Services\Assessment\MeritListService;
use App\Services\Assessment\ReportCardService;
use App\Services\Assessment\ResultProcessingService;
use App\Services\Pdf\ReportCardPdfService;
use App\Services\TeacherPortal\MarkEntryBatchService;
use App\Services\TeacherPortal\MarkModerationService;
use App\Services\TeacherPortal\TeacherHodScopeService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
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

        if (! Schema::hasColumn('report_cards', 'has_absence')) {
            $migration = require database_path(
                'migrations/2026_10_18_070000_add_report_card_absence_snapshots.php'
            );
            $migration->up();
        }

        if (! Schema::hasColumn('exam_results', 'is_absent')) {
            $migration = require database_path('migrations/2026_10_17_090000_add_exam_absence_fields.php');
            $migration->up();
        }

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

        if (! Schema::hasTable('exam_rosters')) {
            $migration = require database_path(
                'migrations/2026_10_17_060000_create_exam_roster_tables.php'
            );
            $migration->up();
        }

        app(ExamRosterService::class)->replace(
            $this->school->id,
            $this->exam->id,
            $this->user->id,
            0,
            [
                [
                    'learner_id' => $this->juniorLearner->id,
                    'exam_learning_area_ids' => [$this->examLearningArea->id],
                ],
                [
                    'learner_id' => $this->primaryLearner->id,
                    'exam_learning_area_ids' => [$this->examLearningArea->id],
                ],
            ]
        );
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

    public function test_processing_uses_roster_education_level_after_transfer(): void
    {
        DB::table('learners')->where('id', $this->juniorLearner->id)->update([
            'grade_id' => $this->primaryLearner->grade_id,
            'stream_id' => $this->primaryLearner->stream_id,
        ]);

        $result = $this->process($this->juniorLearner);

        $this->assertSame('EE2', $result->gradingScale->grade_code);
        $this->assertSame(7, $result->gradingScale->points);
        $this->assertDatabaseHas('learners', [
            'id' => $this->juniorLearner->id,
            'grade_id' => $this->primaryLearner->grade_id,
            'stream_id' => $this->primaryLearner->stream_id,
        ]);
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

    public function test_absence_clears_existing_merit_ranks_and_scored_correction_restores_them(): void
    {
        $this->prepareFreshnessOutputs();

        $identity = [
            'school_id' => $this->school->id,
            'exam_id' => $this->exam->id,
            'learner_id' => $this->juniorLearner->id,
        ];

        $before = DB::table('merit_lists')->where($identity)->first();
        $this->assertNotNull($before->school_position);

        DB::table('exam_results')
            ->where('id', $this->freshnessRawId($this->juniorLearner))
            ->update(['is_absent' => true, 'marks' => null]);

        $this->process($this->juniorLearner);
        $this->generateFreshnessMerits();

        $absent = DB::table('merit_lists')->where($identity)->first();

        $this->assertSame($before->id, $absent->id);
        $this->assertSame('not_ranked_absent', $absent->ranking_method);
        $this->assertSame('generated', $absent->status);

        foreach ([
            'total_score', 'average_percentage', 'total_points',
            'overall_grading_system_id', 'overall_grading_scale_id',
            'school_position', 'grade_position', 'stream_position',
        ] as $field) {
            $this->assertNull($absent->{$field}, $field);
        }

        $other = DB::table('merit_lists')
            ->where('school_id', $this->school->id)
            ->where('exam_id', $this->exam->id)
            ->where('learner_id', $this->primaryLearner->id)
            ->first();

        $this->assertSame(1, (int) $other->school_position);

        DB::table('exam_results')
            ->where('id', $this->freshnessRawId($this->juniorLearner))
            ->update(['is_absent' => false, 'marks' => 40]);

        $this->process($this->juniorLearner);
        $this->generateFreshnessMerits();

        $restored = DB::table('merit_lists')->where($identity)->first();

        $this->assertSame($before->id, $restored->id);
        $this->assertSame('competition', $restored->ranking_method);
        $this->assertSame(75.0, (float) $restored->total_score);
        $this->assertNotNull($restored->overall_grading_scale_id);
        $this->assertNotNull($restored->school_position);
        $this->assertNotNull($restored->grade_position);
        $this->assertNotNull($restored->stream_position);
    }

    public function test_all_absent_learners_keep_merit_entries_without_any_rank(): void
    {
        $this->prepareFreshnessOutputs();

        foreach ([$this->juniorLearner, $this->primaryLearner] as $learner) {
            DB::table('exam_results')
                ->where('id', $this->freshnessRawId($learner))
                ->update(['is_absent' => true, 'marks' => null]);

            $this->process($learner);
        }

        $this->generateFreshnessMerits();

        $rows = DB::table('merit_lists')
            ->where('school_id', $this->school->id)
            ->where('exam_id', $this->exam->id)
            ->get();

        $this->assertCount(2, $rows);

        foreach ($rows as $row) {
            $this->assertSame('not_ranked_absent', $row->ranking_method);
            $this->assertNull($row->total_score);
            $this->assertNull($row->average_percentage);
            $this->assertNull($row->total_points);
            $this->assertNull($row->overall_grading_scale_id);
            $this->assertNull($row->school_position);
            $this->assertNull($row->grade_position);
            $this->assertNull($row->stream_position);
        }
    }

    public function test_report_card_snapshots_absence_and_restores_scores_after_correction(): void
    {
        $this->prepareFreshnessOutputs();

        $identity = [
            'school_id' => $this->school->id,
            'exam_id' => $this->exam->id,
            'learner_id' => $this->juniorLearner->id,
        ];

        $originalId = DB::table('report_cards')->where($identity)->value('id');

        $generateCard = function () {
            return app(ReportCardService::class)->generate(
                $this->school->id,
                $this->exam->id,
                $this->juniorLearner->id,
                null,
                null,
                $this->user->id
            )->first();
        };

        DB::table('exam_results')
            ->where('id', $this->freshnessRawId($this->juniorLearner))
            ->update(['is_absent' => true, 'marks' => null]);

        $this->process($this->juniorLearner);
        $this->generateFreshnessMerits();
        $card = $generateCard();
        $detail = $card->learningAreas->first();

        $this->assertSame($originalId, $card->id);
        $this->assertTrue($card->has_absence);
        $this->assertTrue($detail->is_absent);
        $this->assertNull($card->overall_score);
        $this->assertNull($card->average_percentage);
        $this->assertNull($card->overall_grading_scale_id);
        $this->assertNull($card->total_points);
        $this->assertNull($card->school_position);
        $this->assertNull($card->grade_position);
        $this->assertNull($card->stream_position);
        $this->assertNull($detail->marks_obtained);
        $this->assertNull($detail->percentage);
        $this->assertNull($detail->grade_code);
        $this->assertNull($detail->points);

        $resource = (new ReportCardResource($card))
            ->resolve(request());
        $areaResource = (new ReportCardLearningAreaResource($detail))
            ->resolve(request());

        $this->assertTrue($resource['has_absence']);
        $this->assertTrue($areaResource['is_absent']);
        $this->assertNull($areaResource['marks']);

        $card->load('school.settings');
        $html = view('pdf.report-card', [
            'card' => $card,
            'settings' => $card->school->settings,
            'logo' => null,
            'signature' => null,
        ])->render();

        $this->assertStringContainsString('Not ranked (absence)', $html);
        $this->assertStringContainsString('<td>ABS</td>', $html);

        $pdf = app(ReportCardPdfService::class)->make(
            $this->school->id,
            $card->id
        );
        $this->assertStringStartsWith('%PDF', $pdf['pdf']->output());

        $detailId = $detail->id;

        DB::table('exam_results')
            ->where('id', $this->freshnessRawId($this->juniorLearner))
            ->update(['is_absent' => false, 'marks' => 40]);

        $this->process($this->juniorLearner);
        $this->generateFreshnessMerits();
        $restored = $generateCard();
        $restoredDetail = $restored->learningAreas->first();

        $this->assertSame($originalId, $restored->id);
        $this->assertSame($detailId, $restoredDetail->id);
        $this->assertFalse($restored->has_absence);
        $this->assertFalse($restoredDetail->is_absent);
        $this->assertSame(75.0, (float) $restored->overall_score);
        $this->assertSame(75.0, (float) $restoredDetail->marks_obtained);
        $this->assertNotNull($restored->school_position);
        $this->assertNotNull($restoredDetail->grade_code);
    }

    public function test_absent_merit_entries_follow_ranked_entries_in_generation_and_listing(): void
    {
        $this->prepareFreshnessOutputs();

        DB::table('exam_results')
            ->where('id', $this->freshnessRawId($this->juniorLearner))
            ->update(['is_absent' => true, 'marks' => null]);

        $this->process($this->juniorLearner);
        $rows = $this->generateFreshnessMerits();

        $expected = [$this->primaryLearner->id, $this->juniorLearner->id];
        $this->assertSame($expected, $rows->pluck('learner_id')->all());
        $this->assertSame(1, $rows->first()->school_position);
        $this->assertNull($rows->last()->school_position);

        $this->withoutMiddleware();
        $user = new User;
        $user->forceFill([
            'id' => $this->user->id,
            'school_id' => $this->school->id,
        ]);
        Auth::setUser($user);

        $response = $this->getJson('/api/merit-lists?'.http_build_query([
            'school_id' => $this->school->id,
            'exam_id' => $this->exam->id,
        ]))->assertOk();

        $payload = $response->json('data');
        $items = $payload['data'] ?? $payload;

        $this->assertSame($expected, array_column($items, 'learner_id'));
        $this->assertNull($items[1]['school_position']);
        $this->assertSame('not_ranked_absent', $items[1]['ranking_method']);
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

    public function test_absent_paper_produces_no_learning_area_score_or_grade(): void
    {
        DB::table('exam_results')
            ->where('exam_id', $this->exam->id)
            ->where('learner_id', $this->juniorLearner->id)
            ->where('paper_id', $this->paperOne->id)
            ->update(['is_absent' => true, 'marks' => null]);

        $result = $this->process($this->juniorLearner);

        $this->assertTrue($result->is_absent);
        $this->assertNull($result->marks_obtained);
        $this->assertNull($result->percentage);
        $this->assertNull($result->grading_system_id);
        $this->assertNull($result->grading_scale_id);
        $this->assertNull($result->gradingScale);
        $this->assertSame('100.00', $result->maximum_marks);
        $this->assertSame('processed', $result->processing_status);
    }

    public function test_absence_does_not_hide_a_missing_required_paper(): void
    {
        DB::table('exam_results')
            ->where('learner_id', $this->juniorLearner->id)
            ->where('paper_id', $this->paperOne->id)
            ->update(['is_absent' => true, 'marks' => null]);

        DB::table('exam_results')
            ->where('learner_id', $this->juniorLearner->id)
            ->where('paper_id', $this->paperTwo->id)
            ->delete();

        $this->expectException(ValidationException::class);
        $this->process($this->juniorLearner);
    }

    public function test_unflagged_null_marks_are_not_processed_as_zero(): void
    {
        DB::table('exam_results')
            ->where('learner_id', $this->juniorLearner->id)
            ->where('paper_id', $this->paperOne->id)
            ->update(['is_absent' => false, 'marks' => null]);

        $this->expectException(ValidationException::class);
        $this->process($this->juniorLearner);
    }

    public function test_absent_paper_with_numeric_marks_is_rejected(): void
    {
        DB::table('exam_results')
            ->where('learner_id', $this->juniorLearner->id)
            ->where('paper_id', $this->paperOne->id)
            ->update(['is_absent' => true, 'marks' => 0]);

        $this->expectException(ValidationException::class);
        $this->process($this->juniorLearner);
    }

    public function test_reprocessing_clears_and_restores_grade_without_changing_identity(): void
    {
        $scored = $this->process($this->juniorLearner);
        $id = $scored->id;
        $scaleId = $scored->grading_scale_id;

        DB::table('exam_results')
            ->where('learner_id', $this->juniorLearner->id)
            ->where('paper_id', $this->paperOne->id)
            ->update(['is_absent' => true, 'marks' => null]);

        $absent = $this->process($this->juniorLearner);
        $this->assertSame($id, $absent->id);
        $this->assertTrue($absent->is_absent);
        $this->assertNull($absent->grading_scale_id);
        $this->assertNull($absent->percentage);

        DB::table('exam_results')
            ->where('learner_id', $this->juniorLearner->id)
            ->where('paper_id', $this->paperOne->id)
            ->update(['is_absent' => false, 'marks' => 40]);

        $restored = $this->process($this->juniorLearner);
        $this->assertSame($id, $restored->id);
        $this->assertFalse($restored->is_absent);
        $this->assertSame('75.00', $restored->marks_obtained);
        $this->assertSame('75.00', $restored->percentage);
        $this->assertSame($scaleId, $restored->grading_scale_id);
    }

    public function test_direct_absence_update_invalidates_processing_and_can_return_to_zero(): void
    {
        $this->prepareAbsenceEntryPermission();

        $processed = $this->process($this->juniorLearner);
        $rawId = DB::table('exam_results')
            ->where('learner_id', $this->juniorLearner->id)
            ->where('paper_id', $this->paperOne->id)
            ->value('id');

        $service = app(ExamResultService::class);
        $absent = $service->updateMarks(
            $this->school->id, $rawId, null, $this->user->id, true
        );

        $this->assertTrue($absent->is_absent);
        $this->assertNull($absent->marks);
        $this->assertDatabaseHas('learning_area_results', [
            'id' => $processed->id,
            'processing_status' => 'stale',
        ]);

        $absentProcessed = $this->process($this->juniorLearner);
        $this->assertTrue($absentProcessed->is_absent);
        $this->assertNull($absentProcessed->grading_scale_id);

        $scored = $service->updateMarks(
            $this->school->id, $rawId, 0, $this->user->id
        );

        $this->assertSame($rawId, $scored->id);
        $this->assertFalse($scored->is_absent);
        $this->assertSame('0.00', $scored->marks);
        $this->assertDatabaseHas('learning_area_results', [
            'id' => $processed->id,
            'processing_status' => 'stale',
        ]);

        $restored = $this->process($this->juniorLearner);
        $this->assertFalse($restored->is_absent);
        $this->assertSame('35.00', $restored->marks_obtained);
        $this->assertNotNull($restored->grading_scale_id);
    }

    public function test_direct_creation_restores_deleted_result_as_absent_with_same_identity(): void
    {
        $this->prepareAbsenceEntryPermission();

        $rawId = DB::table('exam_results')
            ->where('learner_id', $this->juniorLearner->id)
            ->where('paper_id', $this->paperOne->id)
            ->value('id');

        $service = app(ExamResultService::class);
        $service->deleteResult($this->school->id, $rawId, $this->user->id);

        $result = $service->create([
            'learner_id' => $this->juniorLearner->id,
            'paper_id' => $this->paperOne->id,
            'is_absent' => true,
        ], $this->school->id, $this->user->id);

        $this->assertSame($rawId, $result->id);
        $this->assertTrue($result->is_absent);
        $this->assertNull($result->marks);
        $this->assertFalse($result->is_deleted);
        $this->assertNull($result->deleted_at);
        $this->assertNull($result->deleted_by);
    }

    public function test_conflicting_direct_absence_update_leaves_result_and_processing_unchanged(): void
    {
        $this->prepareAbsenceEntryPermission();

        $processed = $this->process($this->juniorLearner);
        $rawId = DB::table('exam_results')
            ->where('learner_id', $this->juniorLearner->id)
            ->where('paper_id', $this->paperOne->id)
            ->value('id');

        try {
            app(ExamResultService::class)->updateMarks(
                $this->school->id, $rawId, 0, $this->user->id, true
            );
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('marks', $exception->errors());
            $this->assertDatabaseHas('exam_results', [
                'id' => $rawId,
                'marks' => 40,
                'is_absent' => false,
            ]);
            $this->assertDatabaseHas('learning_area_results', [
                'id' => $processed->id,
                'processing_status' => 'processed',
                'is_absent' => false,
            ]);

            return;
        }

        $this->fail('Expected conflicting absence and marks to be rejected.');
    }

    private function prepareAbsenceEntryPermission(): void
    {
        app(ExamService::class)->transition($this->exam, 'published');
        $this->exam = $this->exam->fresh();

        $permission = MarkEntryPermissionBuilder::create($this->exam);
        $roleName = DB::table('users')
            ->join('roles', 'roles.id', '=', 'users.role_id')
            ->where('users.id', $this->user->id)
            ->value('roles.role_name');

        DB::table('mark_entry_permissions')
            ->where('id', $permission->id)
            ->update([
                'role_name' => $roleName,
                'active' => true,
                'opens_at' => now()->subHour(),
                'closes_at' => now()->addDay(),
            ]);
    }

    public function test_teacher_batch_absence_round_trip_preserves_previous_state(): void
    {
        $this->prepareFreshnessOutputs();
        [$teacher, $assignment] = $this->freshnessTeacherAssignment();
        $service = app(MarkEntryBatchService::class);
        $rawId = $this->freshnessRawId($this->juniorLearner);

        $batch = $service->save($teacher, $this->paperOne->id, [
            ['learner_id' => $this->juniorLearner->id, 'is_absent' => true],
        ], $assignment->id);

        $this->assertTrue($batch->items->first()->is_absent);
        $this->assertNull($batch->items->first()->marks);

        $batch->items->first()->update(['exam_result_id' => $rawId]);
        $batch->update(['status' => 'changes_requested']);

        $submitted = $service->submit($teacher, $batch->id);
        $item = $submitted->items->first();

        $this->assertSame('submitted', $submitted->status);
        $this->assertSame('40.00', $item->previous_marks);
        $this->assertFalse($item->previous_is_absent);
        $this->assertDatabaseHas('exam_results', [
            'id' => $rawId,
            'is_absent' => true,
            'marks' => null,
        ]);
        $this->assertDatabaseHas('learning_area_results', [
            'exam_id' => $this->exam->id,
            'learner_id' => $this->juniorLearner->id,
            'processing_status' => 'stale',
        ]);

        $processed = $this->process($this->juniorLearner);
        $this->assertTrue($processed->is_absent);
        $this->assertNull($processed->percentage);

        $submitted->update(['status' => 'reopened']);
        $service->save($teacher, $this->paperOne->id, [
            ['learner_id' => $this->juniorLearner->id, 'marks' => 0],
        ], $assignment->id);

        $restored = $service->submit($teacher, $batch->id);
        $item = $restored->items->first();

        $this->assertFalse($item->is_absent);
        $this->assertSame('0.00', $item->marks);
        $this->assertTrue($item->previous_is_absent);
        $this->assertNull($item->previous_marks);
        $this->assertSame($rawId, $item->exam_result_id);
        $this->assertDatabaseHas('exam_results', [
            'id' => $rawId,
            'is_absent' => false,
            'marks' => 0,
        ]);

        $processed = $this->process($this->juniorLearner);
        $this->assertFalse($processed->is_absent);
        $this->assertSame('35.00', $processed->marks_obtained);
    }

    public function test_teacher_batch_rejects_missing_marks_without_creating_a_batch(): void
    {
        $this->prepareFreshnessOutputs();
        [$teacher, $assignment] = $this->freshnessTeacherAssignment();

        $this->assertFreshnessRejected(fn () => app(MarkEntryBatchService::class)->save(
            $teacher, $this->paperOne->id, [
                ['learner_id' => $this->juniorLearner->id],
            ], $assignment->id
        ), 'marks');

        $this->assertDatabaseMissing('mark_entry_batches', [
            'exam_paper_id' => $this->paperOne->id,
            'teacher_assignment_id' => $assignment->id,
        ]);
    }

    public function test_published_card_blocks_teacher_absence_submission(): void
    {
        $this->prepareFreshnessOutputs();
        [$teacher, $assignment] = $this->freshnessTeacherAssignment();
        $service = app(MarkEntryBatchService::class);

        $batch = $service->save($teacher, $this->paperOne->id, [
            ['learner_id' => $this->juniorLearner->id, 'is_absent' => true],
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
        $this->assertFreshnessRejected(
            fn () => $service->submit($teacher, $batch->id), 'results'
        );
        $this->assertSame($before, $this->freshnessSnapshot());
        $this->assertSame('reopened', $batch->fresh()->status);
    }

    public function test_approved_absence_corrections_change_source_only_on_resubmission(): void
    {
        $this->prepareFreshnessOutputs();
        [$teacher, $assignment] = $this->freshnessTeacherAssignment();
        $service = app(MarkEntryBatchService::class);
        $rawId = $this->freshnessRawId($this->juniorLearner);

        $batch = $service->save($teacher, $this->paperOne->id, [
            ['learner_id' => $this->juniorLearner->id, 'marks' => 40],
        ], $assignment->id);
        $item = $batch->items->first();
        $item->update(['exam_result_id' => $rawId]);
        $batch->update(['status' => 'submitted', 'submitted_at' => now()]);

        $moderator = User::withoutGlobalScopes()->findOrFail($this->user->id);
        $scope = \Mockery::mock(TeacherHodScopeService::class);
        $scope->shouldReceive('scope')
            ->with(\Mockery::on(fn ($user) => $user->id === $moderator->id))
            ->andReturn([
                'whole_school' => true,
                'school_id' => $this->school->id,
                'learning_area_ids' => [],
            ]);
        $this->app->instance(
            TeacherHodScopeService::class,
            $scope
        );
        $moderation = app(MarkModerationService::class);

        $requestId = $service->correction($teacher, $batch->id, [
            'batch_item_id' => $item->id,
            'reason' => 'Confirm absence from the paper register.',
            'proposed_is_absent' => true,
        ]);

        $this->assertDatabaseHas('mark_correction_requests', [
            'id' => $requestId,
            'previous_marks' => 40,
            'previous_is_absent' => false,
            'proposed_marks' => null,
            'proposed_is_absent' => true,
            'status' => 'pending',
        ]);

        $moderation->decideCorrection($moderator, $requestId, 'approved', 'Register checked.');

        $this->assertSame('reopened', $batch->fresh()->status);
        $this->assertTrue($item->fresh()->is_absent);
        $this->assertNull($item->fresh()->marks);
        $this->assertDatabaseHas('exam_results', [
            'id' => $rawId,
            'marks' => 40,
            'is_absent' => false,
        ]);
        $this->assertDatabaseHas('learning_area_results', [
            'exam_id' => $this->exam->id,
            'learner_id' => $this->juniorLearner->id,
            'processing_status' => 'processed',
        ]);

        $service->submit($teacher, $batch->id);
        $this->assertDatabaseHas('exam_results', [
            'id' => $rawId,
            'marks' => null,
            'is_absent' => true,
        ]);
        $this->assertTrue($this->process($this->juniorLearner)->is_absent);

        $secondId = $service->correction($teacher, $batch->id, [
            'batch_item_id' => $item->id,
            'reason' => 'The script was located and scored zero.',
            'proposed_is_absent' => false,
            'proposed_marks' => 0,
        ]);

        $this->assertDatabaseHas('mark_correction_requests', [
            'id' => $secondId,
            'previous_marks' => null,
            'previous_is_absent' => true,
            'proposed_marks' => 0,
            'proposed_is_absent' => false,
        ]);

        $moderation->decideCorrection($moderator, $secondId, 'approved', 'Script checked.');

        $this->assertFalse($item->fresh()->is_absent);
        $this->assertSame('0.00', $item->fresh()->marks);
        $this->assertDatabaseHas('exam_results', [
            'id' => $rawId,
            'is_absent' => true,
            'marks' => null,
        ]);

        $submitted = $service->submit($teacher, $batch->id);
        $this->assertSame($rawId, $submitted->items->first()->exam_result_id);
        $this->assertTrue($submitted->items->first()->previous_is_absent);
        $this->assertNull($submitted->items->first()->previous_marks);

        $processed = $this->process($this->juniorLearner);
        $this->assertFalse($processed->is_absent);
        $this->assertSame('35.00', $processed->marks_obtained);
        $this->assertNotNull($processed->grading_scale_id);
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
