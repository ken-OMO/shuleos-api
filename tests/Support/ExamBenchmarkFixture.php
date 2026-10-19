<?php

namespace Tests\Support;

use App\Models\Learner;
use App\Services\Assessment\AssessmentTypeService;
use App\Services\Assessment\ExamGradingSnapshotService;
use App\Services\Assessment\ExamLearningAreaService;
use App\Services\Assessment\ExamPaperService;
use App\Services\Assessment\ExamService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\Database\AcademicYearBuilder;
use Tests\Support\Database\EducationLevelBuilder;
use Tests\Support\Database\GradeBuilder;
use Tests\Support\Database\GradingScaleBuilder;
use Tests\Support\Database\GradingSystemBuilder;
use Tests\Support\Database\LearningAreaBuilder;
use Tests\Support\Database\RoleBuilder;
use Tests\Support\Database\SchoolBuilder;
use Tests\Support\Database\StreamBuilder;
use Tests\Support\Database\TermBuilder;
use Tests\Support\Database\UserBuilder;

final class ExamBenchmarkFixture
{
    public static function create(int $count): array
    {
        $database = DB::connection()->getDatabaseName();
        if (! app()->environment('testing')
            || DB::connection()->getDriverName() !== 'pgsql'
            || ! preg_match('/(?:^|_)test(?:ing)?(?:_|$)/i', $database)
            || DB::transactionLevel() < 1
            || $count < 1 || $count > 20000) {
            throw new \LogicException('Benchmark fixtures require a testing database, an outer rollback transaction and 1–20000 learners.');
        }

        $school = SchoolBuilder::create();
        $role = RoleBuilder::create(['role_name' => 'Benchmark '.Str::random(12)]);
        $actor = UserBuilder::create($school, $role);
        $level = EducationLevelBuilder::create([
            'level_name' => 'Primary benchmark '.Str::random(12),
            'level_order' => 1,
        ]);
        $grade = GradeBuilder::create($school, $level);
        $streams = [];
        for ($i = 0; $i < 20; $i++) {
            $streams[] = StreamBuilder::create($school, $grade, [
                'stream_name' => 'Benchmark '.($i + 1),
            ]);
        }
        $year = AcademicYearBuilder::create($school);
        $term = TermBuilder::create($school, $year);
        $system = GradingSystemBuilder::create($school, $level, [
            'uses_points' => false,
        ]);
        GradingScaleBuilder::create($system, [
            'grade_code' => 'ME',
            'grade_description' => 'Meeting expectation',
            'min_score' => 0,
            'max_score' => 100,
            'points' => null,
            'sort_order' => 1,
        ]);
        $type = app(AssessmentTypeService::class)->create([
            'assessment_type_name' => 'Benchmark '.Str::random(12),
        ], $school->id);
        $exam = app(ExamService::class)->create([
            'exam_name' => 'Synthetic benchmark '.Str::random(12),
            'assessment_type_id' => $type->id,
            'academic_year_id' => $year->id,
            'term_id' => $term->id,
            'start_date' => '2026-07-20',
            'end_date' => '2026-07-25',
        ], $school->id, $actor->id);

        $areas = [];
        foreach (range(1, 6) as $number) {
            $area = LearningAreaBuilder::create([
                'learning_area_name' => 'Benchmark '.$number.' '.Str::random(8),
            ]);
            $examArea = app(ExamLearningAreaService::class)->create([
                'exam_id' => $exam->id,
                'learning_area_id' => $area->id,
                'number_of_papers' => 2,
                'total_marks' => 100,
            ], $school->id);
            $papers = [];
            foreach ([1, 2] as $paperNumber) {
                $papers[] = app(ExamPaperService::class)->create([
                    'exam_learning_area_id' => $examArea->id,
                    'paper_name' => 'Paper '.$paperNumber,
                    'paper_number' => $paperNumber,
                    'max_marks' => 50,
                ], $school->id);
            }
            $areas[] = ['area' => $area, 'exam_area' => $examArea, 'papers' => $papers];
        }

        $rosterId = (string) Str::uuid();
        $now = now();
        DB::table('exam_rosters')->insert([
            'id' => $rosterId,
            'school_id' => $school->id,
            'exam_id' => $exam->id,
            'version' => 1,
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $learnerIds = [];
        $award = null;

        for ($offset = 0; $offset < $count; $offset += 100) {
            $learners = [];
            $participants = [];
            $raw = [];
            $requirements = [];
            $processed = [];

            for ($index = $offset; $index < min($count, $offset + 100); $index++) {
                $id = (string) Str::uuid();
                $participantId = (string) Str::uuid();
                $stream = $streams[$index % count($streams)];
                $learnerIds[] = $id;
                $learners[] = [
                    'id' => $id, 'school_id' => $school->id,
                    'admission_no' => 'BENCH-'.str_replace('-', '', $id),
                    'first_name' => 'Synthetic', 'last_name' => 'Learner '.$index,
                    'grade_id' => $grade->id, 'stream_id' => $stream->id,
                    'active' => true, 'is_deleted' => false, 'portal_enabled' => false,
                ];
                $participants[] = [
                    'id' => $participantId, 'roster_id' => $rosterId,
                    'school_id' => $school->id, 'exam_id' => $exam->id,
                    'learner_id' => $id, 'grade_id' => $grade->id,
                    'stream_id' => $stream->id,
                    'created_at' => $now, 'updated_at' => $now,
                ];

                foreach ($areas as $entry) {
                    foreach ($entry['papers'] as $paper) {
                        $raw[] = [
                            'id' => (string) Str::uuid(), 'exam_id' => $exam->id,
                            'learner_id' => $id, 'learning_area_id' => $entry['area']->id,
                            'paper_id' => $paper->id, 'marks' => 40,
                            'is_absent' => false, 'entered_by' => $actor->id,
                            'is_deleted' => false, 'created_at' => $now,
                        ];
                        $requirements[] = [
                            'id' => (string) Str::uuid(),
                            'roster_learner_id' => $participantId,
                            'exam_learning_area_id' => $entry['exam_area']->id,
                            'learning_area_id' => $entry['area']->id,
                            'paper_id' => $paper->id,
                            'paper_number' => $paper->paper_number,
                            'maximum_marks' => 50, 'expected_paper_count' => 2,
                            'learning_area_total_marks' => 100,
                            'created_at' => $now, 'updated_at' => $now,
                        ];
                    }
                }
            }

            DB::table('learners')->insert($learners);
            DB::table('exam_roster_learners')->insert($participants);

            if ($award === null) {
                $award = app(ExamGradingSnapshotService::class)->calculate(
                    $school->id, $exam->id,
                    Learner::withoutGlobalScopes()->findOrFail($learnerIds[0]),
                    80, $actor->id
                );
            }

            foreach ($learners as $learner) {
                foreach ($areas as $entry) {
                    $processed[] = [
                        'id' => (string) Str::uuid(), 'school_id' => $school->id,
                        'exam_id' => $exam->id, 'learner_id' => $learner['id'],
                        'learning_area_id' => $entry['area']->id,
                        'marks_obtained' => 80, 'maximum_marks' => 100,
                        'percentage' => 80, 'is_absent' => false,
                        'grading_system_id' => $award['grading_system_id'],
                        'grading_scale_id' => $award['grading_scale_id'],
                        'grading_snapshot' => json_encode($award, JSON_THROW_ON_ERROR),
                        'processing_status' => 'processed',
                        'processed_by' => $actor->id, 'processed_at' => $now,
                        'is_deleted' => false,
                    ];
                }
            }

            foreach ([
                'exam_roster_papers' => $requirements,
                'exam_results' => $raw,
                'learning_area_results' => $processed,
            ] as $table => $rows) {
                foreach (array_chunk($rows, 300) as $chunk) {
                    DB::table($table)->insert($chunk);
                }
            }
        }

        return [
            'school_id' => $school->id, 'exam_id' => $exam->id,
            'actor_id' => $actor->id, 'learner_ids' => $learnerIds,
            'exam_learning_area_ids' => array_map(fn ($entry) => $entry['exam_area']->id, $areas),
            'learner_count' => $count, 'learning_area_count' => 6,
            'papers_per_area' => 2, 'stream_count' => 20,
            'roster_setup' => 'direct_synthetic_fixture_not_roster_admission',
        ];
    }
}
