<?php

namespace Tests\Support\Database;

use App\Services\Assessment\ExamLearningAreaService;
use App\Services\Assessment\ExamPaperService;
use App\Services\Assessment\ExamRosterService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

final class ExamOutputRosterBuilder
{
    /**
     * Explicit expected population and scores supplied by each test fixture.
     * This helper does not infer expected coverage from processed results.
     */
    public static function create(
        object $school,
        object $exam,
        object $actor,
        array $learners,
        array $learningAreas,
        array $scores
    ): void {
        if (! Schema::hasTable('exam_rosters')) {
            $migration = require database_path(
                'migrations/2026_10_17_060000_create_exam_roster_tables.php'
            );
            $migration->up();
        }

        if (! Schema::hasColumn('exam_results', 'is_absent')) {
            $migration = require database_path(
                'migrations/2026_10_17_090000_add_exam_absence_fields.php'
            );
            $migration->up();
        }

        $areaIds = [];

        foreach ($learningAreas as $learningArea) {
            $area = app(ExamLearningAreaService::class)->create([
                'exam_id' => $exam->id,
                'learning_area_id' => $learningArea->id,
                'number_of_papers' => 1,
                'total_marks' => 100,
            ], $school->id);

            $paper = app(ExamPaperService::class)->create([
                'exam_learning_area_id' => $area->id,
                'paper_name' => 'Fixture Paper 1',
                'paper_number' => 1,
                'max_marks' => 100,
            ], $school->id);

            $areaIds[] = $area->id;

            foreach ($learners as $learner) {
                if (! array_key_exists($learner->id, $scores)) {
                    throw new \LogicException('Every expected learner needs an explicit fixture score.');
                }

                DB::table('exam_results')->insert([
                    'id' => (string) Str::uuid(),
                    'exam_id' => $exam->id,
                    'learner_id' => $learner->id,
                    'learning_area_id' => $learningArea->id,
                    'paper_id' => $paper->id,
                    'marks' => $scores[$learner->id],
                    'is_absent' => false,
                    'entered_by' => $actor->id,
                    'is_deleted' => false,
                    'created_at' => now(),
                ]);
            }
        }

        app(ExamRosterService::class)->replace(
            $school->id,
            $exam->id,
            $actor->id,
            0,
            array_map(fn ($learner) => [
                'learner_id' => $learner->id,
                'exam_learning_area_ids' => $areaIds,
            ], $learners)
        );
    }
}
