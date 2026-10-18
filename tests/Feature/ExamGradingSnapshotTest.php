<?php

namespace Tests\Feature;

use App\Http\Resources\LearningAreaResultResource;
use App\Models\Learner;
use App\Services\Assessment\ExamGradingSnapshotService;
use App\Services\Assessment\MeritListService;
use App\Services\Assessment\ReportCardService;
use App\Services\Assessment\ResultProcessingService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\Database\SchoolBuilder;

class ExamGradingSnapshotTest extends LearningAreaResultTest
{
    public function test_publication_rejects_legacy_awards_without_partial_writes(): void
    {
        [$exam] = $this->snapshotContext();
        $areaId = DB::table('exam_learning_areas')
            ->where('exam_id', $exam->id)->value('id');

        foreach (DB::table('exam_results')->where('exam_id', $exam->id)
            ->pluck('learner_id')->unique() as $learnerId) {
            app(ResultProcessingService::class)->process(
                $exam->school_id, $areaId, $learnerId, $exam->created_by
            );
        }

        $merits = app(MeritListService::class)->generate(
            $exam->school_id, $exam->id, null, null, $exam->created_by
        );
        $merit = $merits->first();
        $award = $merit->grading_snapshot;

        DB::table('merit_lists')->where('id', $merit->id)
            ->update(['grading_snapshot' => null]);

        $this->rejectSnapshot(
            fn () => app(MeritListService::class)->publish(
                $exam->school_id, $exam->id, null, null
            ),
            'grading_snapshot'
        );
        $this->assertSame(0, DB::table('merit_lists')
            ->where('exam_id', $exam->id)->where('status', 'published')->count());

        DB::table('merit_lists')->where('id', $merit->id)
            ->update(['grading_snapshot' => json_encode($award, JSON_THROW_ON_ERROR)]);

        $cards = app(ReportCardService::class)->generate(
            $exam->school_id, $exam->id, null, null, null, $exam->created_by
        );
        $detail = DB::table('report_card_learning_areas')
            ->whereIn('report_card_id', $cards->pluck('id'))->first();

        DB::table('report_card_learning_areas')->where('id', $detail->id)
            ->update(['grading_snapshot' => null]);

        $this->rejectSnapshot(
            fn () => app(ReportCardService::class)->publish(
                $exam->school_id, $exam->id, null, null, null, $exam->created_by
            ),
            'grading_snapshot'
        );
        $this->assertSame(0, DB::table('report_cards')
            ->where('exam_id', $exam->id)->where('status', 'published')->count());

        DB::table('report_card_learning_areas')->where('id', $detail->id)
            ->update(['grading_snapshot' => $detail->grading_snapshot]);

        app(ReportCardService::class)->publish(
            $exam->school_id, $exam->id, null, null, null, $exam->created_by
        );
        $this->assertSame($cards->count(), DB::table('report_cards')
            ->where('exam_id', $exam->id)->where('status', 'published')->count());
    }

    protected function setUp(): void
    {
        parent::setUp();

        if (! Schema::hasTable('exam_grading_snapshots')) {
            $migration = require database_path(
                'migrations/2026_10_18_140000_add_exam_grading_snapshots.php'
            );
            $migration->up();
        }
    }

    public function test_captured_rules_survive_scale_edits_and_system_deactivation(): void
    {
        [$exam, $learner] = $this->snapshotContext();
        $first = $this->award($exam, $learner, 75);
        $before = DB::table('exam_grading_snapshots')->first();

        $this->assertSame('EE2', $first['grade_code']);
        $this->assertSame(7, $first['points']);

        DB::table('grading_scales')->where('id', $first['grading_scale_id'])->update([
            'grade_code' => 'CHANGED',
            'grade_description' => 'Changed description',
            'points' => 1,
            'min_score' => 90,
        ]);
        DB::table('grading_systems')
            ->where('id', $first['grading_system_id'])->update(['active' => false]);

        $second = $this->award($exam, $learner, 75);

        $this->assertSame($first, $second);
        $this->assertEquals($before, DB::table('exam_grading_snapshots')->first());
        $this->assertSame(1, DB::table('exam_grading_snapshots')->count());

        $lower = $this->award($exam, $learner, 50);
        $this->assertSame('ME', $lower['grade_code']);
        $this->assertNull($lower['points']);
        $this->assertSame($first['rules_snapshot_id'], $lower['rules_snapshot_id']);
    }

    public function test_invalid_percentage_does_not_capture_rules(): void
    {
        [$exam, $learner] = $this->snapshotContext();

        foreach ([-0.01, 100.01, INF, NAN] as $percentage) {
            $this->rejectSnapshot(
                fn () => $this->award($exam, $learner, $percentage),
                'percentage'
            );
        }

        $this->assertSame(0, DB::table('exam_grading_snapshots')->count());
    }

    public function test_cross_school_exam_and_capture_actor_are_rejected(): void
    {
        [$exam, $learner] = $this->snapshotContext();
        $other = SchoolBuilder::create();

        $this->rejectSnapshot(fn () => DB::transaction(
            fn () => app(ExamGradingSnapshotService::class)->calculate(
                $other->id, $exam->id, $learner, 75, $exam->created_by
            )
        ), 'exam_id');

        $this->rejectSnapshot(fn () => DB::transaction(
            fn () => app(ExamGradingSnapshotService::class)->calculate(
                $exam->school_id, $exam->id, $learner, 75, (string) Str::uuid()
            )
        ), 'user_id');

        $this->assertSame(0, DB::table('exam_grading_snapshots')->count());
    }

    public function test_unmatched_grade_does_not_persist_a_rules_snapshot(): void
    {
        [$exam, $learner] = $this->snapshotContext();
        $systemId = DB::table('grading_systems')
            ->where('school_id', $exam->school_id)
            ->where('education_level_id', $learner->grade->education_level_id)
            ->where('active', true)->value('id');

        DB::table('grading_scales')->where('grading_system_id', $systemId)
            ->where('min_score', '>=', 75)->update(['min_score' => 90]);

        $this->rejectSnapshot(
            fn () => $this->award($exam, $learner, 75),
            'grading_scale'
        );

        $this->assertSame(0, DB::table('exam_grading_snapshots')->count());
    }

    public function test_rules_capture_rolls_back_with_the_callers_transaction(): void
    {
        [$exam, $learner] = $this->snapshotContext();

        try {
            DB::transaction(function () use ($exam, $learner) {
                $this->award($exam, $learner, 75);
                throw new \RuntimeException('Rollback snapshot fixture.');
            });
        } catch (\RuntimeException $exception) {
            $this->assertSame('Rollback snapshot fixture.', $exception->getMessage());
        }

        $this->assertSame(0, DB::table('exam_grading_snapshots')->count());
    }

    public function test_existing_processed_results_are_not_automatically_backfilled(): void
    {
        [$exam, $learner] = $this->snapshotContext();
        $area = DB::table('exam_learning_areas')->where('exam_id', $exam->id)->first();

        $result = app(ResultProcessingService::class)->process(
            $exam->school_id, $area->id, $learner->id, $exam->created_by
        );

        $this->assertNotNull(DB::table('learning_area_results')
            ->where('id', $result->id)->value('grading_snapshot'));

        // Explicitly simulate a pre-snapshot row; reading rules must not backfill it.
        DB::table('learning_area_results')->where('id', $result->id)
            ->update(['grading_snapshot' => null]);

        $this->award($exam, $learner, 75);

        $this->assertNull(DB::table('learning_area_results')
            ->where('id', $result->id)->value('grading_snapshot'));
    }

    public function test_legacy_published_outputs_block_initial_rule_capture(): void
    {
        [$exam, $learner] = $this->snapshotContext();
        $area = DB::table('exam_learning_areas')->where('exam_id', $exam->id)->first();

        $ids = DB::table('exam_roster_learners')
            ->where('exam_id', $exam->id)->pluck('learner_id');

        foreach ($ids as $id) {
            app(ResultProcessingService::class)->process(
                $exam->school_id, $area->id, $id, $exam->created_by
            );
        }

        app(MeritListService::class)->generate(
            $exam->school_id, $exam->id, null, null, $exam->created_by
        );
        app(MeritListService::class)->publish(
            $exam->school_id, $exam->id, null, null
        );

        // Simulate an already published exam from before snapshot support.
        DB::table('learning_area_results')->where('exam_id', $exam->id)
            ->update(['grading_snapshot' => null]);
        DB::table('exam_grading_snapshots')->where('exam_id', $exam->id)->delete();

        $this->rejectSnapshot(
            fn () => $this->award($exam, $learner, 75),
            'results'
        );

        $this->assertSame(0, DB::table('exam_grading_snapshots')->count());
    }

    public function test_reprocessing_and_resource_keep_captured_grade_after_scale_edit(): void
    {
        [$exam, $learner] = $this->snapshotContext();
        $area = DB::table('exam_learning_areas')->where('exam_id', $exam->id)->first();

        $first = app(ResultProcessingService::class)->process(
            $exam->school_id, $area->id, $learner->id, $exam->created_by
        );
        $award = $first->grading_snapshot;

        DB::table('grading_scales')->where('id', $first->grading_scale_id)->update([
            'grade_code' => 'CHANGED',
            'grade_description' => 'Changed description',
            'points' => 1,
            'min_score' => 90,
        ]);

        $second = app(ResultProcessingService::class)->process(
            $exam->school_id, $area->id, $learner->id, $exam->created_by
        );

        $this->assertSame($first->id, $second->id);
        $this->assertSame($award, $second->grading_snapshot);

        $data = (new LearningAreaResultResource($second))
            ->resolve(request());

        $this->assertSame('EE2', $data['grade_code']);
        $this->assertSame('Exceeding Expectation', $data['grade_description']);
        $this->assertSame(7, $data['points']);
    }

    private function snapshotContext(): array
    {
        $actor = DB::table('users')
            ->where('role_id', DB::table('roles')
                ->where('role_name', 'Results Processor')->value('id'))
            ->first();

        $exam = DB::table('exams')->where('created_by', $actor->id)->first();
        $learner = Learner::withoutGlobalScopes()
            ->with('grade.educationLevel')
            ->where('school_id', $exam->school_id)
            ->whereHas('grade', fn ($query) => $query
                ->where('grade_name', 'like', 'Junior Grade %'))
            ->firstOrFail();

        return [$exam, $learner];
    }

    private function award(object $exam, Learner $learner, float $percentage): array
    {
        return DB::transaction(
            fn () => app(ExamGradingSnapshotService::class)->calculate(
                $exam->school_id, $exam->id, $learner,
                $percentage, $exam->created_by
            )
        );
    }

    private function rejectSnapshot(callable $operation, string $field): void
    {
        try {
            $operation();
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($field, $exception->errors());

            return;
        }

        $this->fail("Expected snapshot validation failure for {$field}.");
    }
}
