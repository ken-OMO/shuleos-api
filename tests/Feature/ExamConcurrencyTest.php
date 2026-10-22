<?php

namespace Tests\Feature;

use App\Services\Assessment\AssessmentTypeService;
use App\Services\Assessment\ExamPaperService;
use App\Services\Assessment\ExamService;
use App\Services\Assessment\MeritListService;
use App\Services\Assessment\ReportCardService;
use App\Services\Assessment\ResultProcessingService;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\Database\AcademicYearBuilder;
use Tests\Support\Database\EducationLevelBuilder;
use Tests\Support\Database\ExamOutputRosterBuilder;
use Tests\Support\Database\GradeBuilder;
use Tests\Support\Database\GradingScaleBuilder;
use Tests\Support\Database\GradingSystemBuilder;
use Tests\Support\Database\LearnerBuilder;
use Tests\Support\Database\LearningAreaBuilder;
use Tests\Support\Database\MarkEntryPermissionBuilder;
use Tests\Support\Database\RoleBuilder;
use Tests\Support\Database\SchoolBuilder;
use Tests\Support\Database\StreamBuilder;
use Tests\Support\Database\TermBuilder;
use Tests\Support\Database\UserBuilder;
use Tests\Support\ExamConcurrencyRunner;
use Tests\TestCase;

class ExamConcurrencyTest extends TestCase
{
    private array $fixture = [];

    protected function setUp(): void
    {
        parent::setUp();

        $connection = DB::connection()->getConfig();
        if (! app()->environment('testing')
            || ($connection['driver'] ?? '') !== 'pgsql'
            || ! preg_match('/(?:^|_)test(?:ing)?(?:_|$)/i', $connection['database'] ?? '')
            || ! empty($connection['url'])
            || DB::transactionLevel() !== 0) {
            throw new \RuntimeException('Concurrency fixtures require a PostgreSQL testing database without an outer transaction.');
        }

        DB::transaction(function () {
            $school = SchoolBuilder::create();
            $role = RoleBuilder::create(['role_name' => 'Concurrency '.Str::random(12)]);
            $actor = UserBuilder::create($school, $role);
            $level = EducationLevelBuilder::create([
                'level_name' => 'Primary concurrency '.Str::random(12),
                'level_order' => 1,
            ]);
            $grade = GradeBuilder::create($school, $level);
            $stream = StreamBuilder::create($school, $grade);
            $learner = LearnerBuilder::create($school, $grade, $stream);
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
                'assessment_type_name' => 'Concurrency '.Str::random(12),
            ], $school->id);
            $exam = app(ExamService::class)->create([
                'exam_name' => 'Concurrency '.Str::random(12),
                'assessment_type_id' => $type->id,
                'academic_year_id' => $year->id,
                'term_id' => $term->id,
                'start_date' => '2026-07-20',
                'end_date' => '2026-07-25',
            ], $school->id, $actor->id);
            $area = LearningAreaBuilder::create([
                'learning_area_name' => 'Concurrency '.Str::random(12),
            ]);

            ExamOutputRosterBuilder::create(
                $school, $exam, $actor, [$learner], [$area],
                [$learner->id => 80]
            );
            $examAreaId = DB::table('exam_learning_areas')
                ->where('exam_id', $exam->id)->value('id');

            $processed = app(ResultProcessingService::class)->process(
                $school->id, $examAreaId, $learner->id, $actor->id
            );
            app(MeritListService::class)->generate(
                $school->id, $exam->id, null, null, $actor->id
            );
            $card = app(ReportCardService::class)->generate(
                $school->id, $exam->id, $learner->id, null, null, $actor->id
            )->first();

            $permission = MarkEntryPermissionBuilder::create($exam);
            DB::table('mark_entry_permissions')->where('id', $permission->id)->update([
                'role_name' => $role->role_name,
                'active' => true,
                'opens_at' => now()->subHour(),
                'closes_at' => now()->addDay(),
            ]);

            $this->fixture = [
                'school_id' => $school->id,
                'role_id' => $role->id,
                'actor_id' => $actor->id,
                'level_id' => $level->id,
                'area_id' => $area->id,
                'exam_id' => $exam->id,
                'exam_learning_area_id' => $examAreaId,
                'learner_id' => $learner->id,
                'result_id' => DB::table('exam_results')
                    ->where('exam_id', $exam->id)->value('id'),
                'processed_id' => $processed->id,
                'card_id' => $card->id,
            ];
        });
    }

    protected function tearDown(): void
    {
        try {
            if ($this->fixture !== []) {
                $this->removeFixtures();
            }
        } finally {
            parent::tearDown();
        }
    }

    public function test_publication_first_blocks_waiting_mark_change(): void
    {
        $before = (array) DB::table('exam_results')
            ->where('id', $this->fixture['result_id'])->first();

        $outcome = $this->race('publish', 'marks');

        $this->assertTrue($outcome['contention_verified']);
        $this->assertSame('finished', $outcome['first']['event']);
        $this->assertSame('rejected', $outcome['second']['event']);
        $this->assertContains('results', $outcome['second']['fields']);
        $this->assertSame(
            $before,
            (array) DB::table('exam_results')
                ->where('id', $this->fixture['result_id'])->first()
        );
        $this->assertDatabaseHas('report_cards', [
            'id' => $this->fixture['card_id'],
            'status' => 'published',
        ]);
    }

    public function test_mark_change_first_blocks_waiting_stale_publication(): void
    {
        $outcome = $this->race('marks', 'publish');

        $this->assertTrue($outcome['contention_verified']);
        $this->assertSame('finished', $outcome['first']['event']);
        $this->assertSame('rejected', $outcome['second']['event']);
        $this->assertDatabaseHas('exam_results', [
            'id' => $this->fixture['result_id'],
            'marks' => 65,
        ]);
        $this->assertDatabaseHas('learning_area_results', [
            'id' => $this->fixture['processed_id'],
            'processing_status' => 'stale',
        ]);
        $this->assertDatabaseHas('report_cards', [
            'id' => $this->fixture['card_id'],
            'status' => 'stale',
        ]);
        $this->assertDatabaseMissing('report_cards', [
            'exam_id' => $this->fixture['exam_id'],
            'status' => 'published',
        ]);
    }

    public function test_competing_processing_creates_one_shared_result_identity(): void
    {
        DB::table('report_card_learning_areas')
            ->where('report_card_id', $this->fixture['card_id'])->delete();

        DB::table('learning_area_results')
            ->where('id', $this->fixture['processed_id'])->delete();

        $outcome = $this->race('process', 'process');

        $this->assertTrue($outcome['contention_verified']);
        $this->assertSame('finished', $outcome['first']['event']);
        $this->assertSame('finished', $outcome['second']['event']);
        $this->assertSame($outcome['first']['result'], $outcome['second']['result']);
        $this->assertSame(1, DB::table('learning_area_results')
            ->where('exam_id', $this->fixture['exam_id'])
            ->where('learner_id', $this->fixture['learner_id'])->count());
        $this->assertDatabaseHas('learning_area_results', [
            'id' => $outcome['first']['result'],
            'processing_status' => 'processed',
            'marks_obtained' => 80,
        ]);
    }

    public function test_competing_roster_replacement_rejects_outdated_version(): void
    {
        $version = (int) DB::table('exam_rosters')
            ->where('exam_id', $this->fixture['exam_id'])->value('version');
        $extra = [
            'version' => $version,
            'selections' => [[
                'learner_id' => $this->fixture['learner_id'],
                'exam_learning_area_ids' => [$this->fixture['exam_learning_area_id']],
            ]],
        ];

        $outcome = $this->race('roster', 'roster', $extra);

        $this->assertTrue($outcome['contention_verified']);
        $this->assertSame('finished', $outcome['first']['event']);
        $this->assertSame($version + 1, $outcome['first']['result']);
        $this->assertSame('rejected', $outcome['second']['event']);
        $this->assertContains('version', $outcome['second']['fields']);
        $this->assertSame($version + 1, (int) DB::table('exam_rosters')
            ->where('exam_id', $this->fixture['exam_id'])->value('version'));
        $this->assertSame(1, DB::table('exam_roster_learners')
            ->where('exam_id', $this->fixture['exam_id'])->count());
    }

    public function test_read_only_transition_first_rejects_waiting_mark_change(): void
    {
        $before = (array) DB::table('exam_results')
            ->where('id', $this->fixture['result_id'])->first();

        $outcome = $this->race('school_read_only', 'marks');

        $this->assertTrue($outcome['contention_verified']);
        $this->assertSame('finished', $outcome['first']['event']);
        $this->assertSame(0, $outcome['first']['exit_code']);
        $this->assertSame('rejected', $outcome['second']['event']);
        $this->assertSame(2, $outcome['second']['exit_code']);
        $this->assertContains('school_access', $outcome['second']['fields']);
        $this->assertSame(
            $before,
            (array) DB::table('exam_results')
                ->where('id', $this->fixture['result_id'])->first()
        );
        $this->assertDatabaseHas('schools', [
            'id' => $this->fixture['school_id'],
            'lifecycle_state' => 'read_only',
        ]);
    }

    public function test_mark_write_first_completes_before_waiting_read_only_transition(): void
    {
        $outcome = $this->race('marks', 'school_read_only');

        $this->assertTrue($outcome['contention_verified']);
        foreach (['first', 'second'] as $worker) {
            $this->assertSame('finished', $outcome[$worker]['event']);
            $this->assertSame(0, $outcome[$worker]['exit_code']);
        }
        $this->assertDatabaseHas('exam_results', [
            'id' => $this->fixture['result_id'],
            'marks' => 65,
        ]);
        $this->assertDatabaseHas('schools', [
            'id' => $this->fixture['school_id'],
            'lifecycle_state' => 'read_only',
        ]);
        $this->assertDatabaseHas('report_cards', [
            'id' => $this->fixture['card_id'],
            'status' => 'stale',
        ]);
    }

    public function test_competing_paper_inserts_cannot_exceed_total_marks(): void
    {
        $this->preparePaperRace(2);

        $outcome = $this->paperRace(60, 60);

        $this->assertTrue($outcome['contention_verified']);
        $this->assertSame('finished', $outcome['first']['event']);
        $this->assertSame(0, $outcome['first']['exit_code']);
        $this->assertSame('rejected', $outcome['second']['event']);
        $this->assertSame(2, $outcome['second']['exit_code']);
        $this->assertContains('max_marks', $outcome['second']['fields']);
        $this->assertSame(1, $this->currentRacePapers()->count());
        $this->assertSame(60, (int) $this->currentRacePapers()->sum('max_marks'));
        $this->assertSame(
            $outcome['first']['result'], $this->currentRacePapers()->value('id')
        );
    }

    public function test_competing_paper_inserts_cannot_exceed_declared_count(): void
    {
        $this->preparePaperRace(1);

        $outcome = $this->paperRace(40, 40);

        $this->assertTrue($outcome['contention_verified']);
        $this->assertSame('finished', $outcome['first']['event']);
        $this->assertSame(0, $outcome['first']['exit_code']);
        $this->assertSame('rejected', $outcome['second']['event']);
        $this->assertSame(2, $outcome['second']['exit_code']);
        $this->assertContains('paper_number', $outcome['second']['fields']);
        $this->assertSame(1, $this->currentRacePapers()->count());
        $this->assertSame(1, (int) $this->currentRacePapers()->value('paper_number'));
    }

    public function test_exam_publication_rejects_waiting_paper_creation(): void
    {
        $this->preparePaperRace(2);

        $outcome = $this->race('exam_publish', 'paper_create', [
            'paper' => $this->racePaper(1, 50),
        ]);

        $this->assertTrue($outcome['contention_verified']);
        $this->assertSame('finished', $outcome['first']['event']);
        $this->assertSame(0, $outcome['first']['exit_code']);
        $this->assertSame('rejected', $outcome['second']['event']);
        $this->assertSame(2, $outcome['second']['exit_code']);
        $this->assertContains(
            'exam_learning_area_id', $outcome['second']['fields']
        );
        $this->assertSame(0, $this->currentRacePapers()->count());
        $this->assertDatabaseHas('exams', [
            'id' => $this->fixture['exam_id'], 'status' => 'published',
        ]);
    }

    public function test_competing_paper_updates_cannot_exceed_total_marks(): void
    {
        $this->preparePaperRace(2);
        $service = app(ExamPaperService::class);
        $first = $service->create($this->racePaper(1, 40), $this->fixture['school_id']);
        $second = $service->create($this->racePaper(2, 40), $this->fixture['school_id']);
        $secondBefore = $second->fresh()->getAttributes();

        $outcome = (new ExamConcurrencyRunner)->run(
            ['operation' => 'paper_update', 'paper_id' => $first->id,
                'max_marks' => 60] + $this->fixture,
            ['operation' => 'paper_update', 'paper_id' => $second->id,
                'max_marks' => 60] + $this->fixture
        );

        $this->assertTrue($outcome['contention_verified']);
        $this->assertSame('finished', $outcome['first']['event']);
        $this->assertSame(0, $outcome['first']['exit_code']);
        $this->assertSame('rejected', $outcome['second']['event']);
        $this->assertSame(2, $outcome['second']['exit_code']);
        $this->assertContains('max_marks', $outcome['second']['fields']);
        $this->assertSame(60, $first->fresh()->max_marks);
        $this->assertSame($secondBefore, $second->fresh()->getAttributes());
        $this->assertSame(100, (int) $this->currentRacePapers()->sum('max_marks'));
    }

    public function test_area_deletion_first_rejects_waiting_paper_creation(): void
    {
        $this->preparePaperRace(2);

        $outcome = $this->race('area_delete', 'paper_create', [
            'paper' => $this->racePaper(1, 50),
        ]);

        $this->assertTrue($outcome['contention_verified']);
        $this->assertSame('finished', $outcome['first']['event']);
        $this->assertSame(0, $outcome['first']['exit_code']);
        $this->assertSame('rejected', $outcome['second']['event']);
        $this->assertSame(2, $outcome['second']['exit_code']);
        $this->assertContains(
            'exam_learning_area_id', $outcome['second']['fields']
        );
        $this->assertSame(0, $this->currentRacePapers()->count());
        $this->assertDatabaseHas('exam_learning_areas', [
            'id' => $this->fixture['exam_learning_area_id'],
            'is_deleted' => true,
            'deleted_by' => $this->fixture['actor_id'],
        ]);
    }

    public function test_paper_creation_first_rejects_waiting_area_deletion(): void
    {
        $this->preparePaperRace(2);

        $outcome = $this->race('paper_create', 'area_delete', [
            'paper' => $this->racePaper(1, 50),
        ]);

        $this->assertTrue($outcome['contention_verified']);
        $this->assertSame('finished', $outcome['first']['event']);
        $this->assertSame(0, $outcome['first']['exit_code']);
        $this->assertSame('rejected', $outcome['second']['event']);
        $this->assertSame(2, $outcome['second']['exit_code']);
        $this->assertSame(400, $outcome['second']['http_status']);
        $this->assertSame(1, $this->currentRacePapers()->count());
        $this->assertDatabaseHas('exam_learning_areas', [
            'id' => $this->fixture['exam_learning_area_id'],
            'is_deleted' => false,
            'deleted_at' => null,
        ]);
    }

    private function preparePaperRace(int $declaredCount): void
    {
        // Remove dependent synthetic results before replacing paper configuration.
        DB::transaction(function () use ($declaredCount) {
            DB::table('report_card_learning_areas')
                ->where('report_card_id', $this->fixture['card_id'])->delete();

            foreach (['learning_area_results', 'exam_results', 'exam_rosters'] as $table) {
                DB::table($table)
                    ->where('exam_id', $this->fixture['exam_id'])->delete();
            }

            DB::table('exam_papers')
                ->where('exam_learning_area_id', $this->fixture['exam_learning_area_id'])
                ->delete();

            DB::table('exams')->where('id', $this->fixture['exam_id'])
                ->update(['status' => 'draft']);

            DB::table('exam_learning_areas')
                ->where('id', $this->fixture['exam_learning_area_id'])
                ->update(['number_of_papers' => $declaredCount, 'total_marks' => 100]);
        });
    }

    private function racePaper(int $number, int $marks): array
    {
        return [
            'exam_learning_area_id' => $this->fixture['exam_learning_area_id'],
            'paper_name' => 'Concurrent paper '.$number,
            'paper_number' => $number,
            'max_marks' => $marks,
        ];
    }

    private function currentRacePapers(): Builder
    {
        return DB::table('exam_papers')
            ->where('exam_learning_area_id', $this->fixture['exam_learning_area_id'])
            ->where('is_deleted', false);
    }

    private function paperRace(int $firstMarks, int $secondMarks): array
    {
        return (new ExamConcurrencyRunner)->run(
            ['operation' => 'paper_create',
                'paper' => $this->racePaper(1, $firstMarks)] + $this->fixture,
            ['operation' => 'paper_create',
                'paper' => $this->racePaper(2, $secondMarks)] + $this->fixture
        );
    }

    private function race(string $first, string $second, array $extra = []): array
    {
        $base = $extra + $this->fixture + ['marks' => 65];

        return (new ExamConcurrencyRunner)->run(
            ['operation' => $first] + $base,
            ['operation' => $second] + $base
        );
    }

    private function removeFixtures(): void
    {
        $schoolId = $this->fixture['school_id'];
        $examId = $this->fixture['exam_id'];

        DB::transaction(function () use ($schoolId, $examId) {
            $cardIds = DB::table('report_cards')->where('exam_id', $examId)->pluck('id');
            DB::table('report_card_learning_areas')->whereIn('report_card_id', $cardIds)->delete();

            foreach (['report_cards', 'merit_lists', 'learning_area_results', 'exam_results',
                'exam_rosters', 'exam_grading_snapshots', 'mark_entry_permissions'] as $table) {
                DB::table($table)->where('exam_id', $examId)->delete();
            }

            $areaIds = DB::table('exam_learning_areas')->where('exam_id', $examId)->pluck('id');
            DB::table('exam_papers')->whereIn('exam_learning_area_id', $areaIds)->delete();
            DB::table('exam_learning_areas')->where('exam_id', $examId)->delete();
            DB::table('exams')->where('id', $examId)->delete();

            $systemIds = DB::table('grading_systems')->where('school_id', $schoolId)->pluck('id');
            DB::table('grading_scales')->whereIn('grading_system_id', $systemIds)->delete();

            foreach (['grading_systems', 'learners', 'streams', 'grades',
                'terms', 'academic_years', 'assessment_types', 'users'] as $table) {
                DB::table($table)->where('school_id', $schoolId)->delete();
            }

            DB::table('schools')->where('id', $schoolId)->delete();
            DB::table('roles')->where('id', $this->fixture['role_id'])->delete();
            DB::table('education_levels')->where('id', $this->fixture['level_id'])->delete();
            DB::table('learning_areas')->where('id', $this->fixture['area_id'])->delete();
        });
    }
}
