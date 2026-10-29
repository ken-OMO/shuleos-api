<?php

namespace Tests\Feature;

use App\Services\Assessment\ExamRosterService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Support\Database\GradeBuilder;
use Tests\Support\Database\LearnerBuilder;
use Tests\Support\Database\StreamBuilder;
use Tests\Support\Database\UserBuilder;

class ExamRosterTenantIntegrityTest extends ReportCardTest
{
    protected function setUp(): void
    {
        parent::setUp();

        $database = DB::selectOne('SELECT current_database() AS name')->name;
        if (! app()->environment('testing')
            || ! in_array($database, [
                'shuleos_test',
                'shuleos_testing',
                'shuleos_assessment_fk_test',
            ], true)
            || DB::transactionLevel() < 1) {
            throw new \RuntimeException('An isolated transactional test database is required.');
        }

        // Restore the pre-hardening schema only within this test transaction.
        if (Schema::hasColumn('exam_roster_papers', 'exam_id')) {
            $migration = require database_path(
                'migrations/2026_10_29_110000_harden_exam_roster_relationships.php'
            );
            $migration->down();
        }

        $installed = DB::selectOne(
            "SELECT 1 AS present FROM pg_constraint
             WHERE conrelid = 'public.assessment_reissue_requests'::regclass
               AND conname = 'assessment_request_exam_tenant_fk'"
        );

        if ($installed) {
            $migration = require database_path(
                'migrations/2026_10_29_100000_harden_assessment_reissue_tenant_integrity.php'
            );
            $migration->down();
        }
    }

    public function test_roster_hardening_backfills_and_rejects_invalid_references(): void
    {
        $f = $this->rosterIntegrityFixture();
        $before = $this->rosterRecords();
        $this->applyReissueHardening();
        $migration = $this->rosterMigration();
        DB::transaction(fn () => $migration->up());

        $this->assertSame($before, $this->rosterRecords());

        foreach (DB::table('exam_roster_papers')->get() as $paper) {
            $this->assertSame(
                DB::table('exam_roster_learners')
                    ->where('id', $paper->roster_learner_id)->value('exam_id'),
                $paper->exam_id
            );
        }

        $this->assertFalse(Schema::getConnection()->getSchemaBuilder()
            ->getColumns('exam_roster_papers') === []);

        $cases = [
            ['exam_rosters', $f['roster'], 'exam_id', $f['foreignExam']->id],
            ['exam_rosters', $f['roster'], 'created_by', $f['foreignActor']->id],
            ['exam_rosters', $f['roster'], 'updated_by', $f['foreignActor']->id],
            ['exam_roster_learners', $f['participant'], 'learner_id', $f['foreignLearner']->id],
            ['exam_roster_learners', $f['participant'], 'grade_id', $f['foreignGrade']->id],
            ['exam_roster_learners', $f['participant'], 'stream_id', $f['foreignStream']->id],
            ['exam_roster_papers', $f['paper'], 'roster_learner_id', $f['otherParticipant']['id']],
            ['exam_roster_papers', $f['paper'], 'exam_learning_area_id', $f['otherArea']->id],
            ['exam_roster_papers', $f['paper'], 'paper_id', $f['otherPaper']->id],
            ['exam_roster_papers', $f['paper'], 'exam_id', $f['otherExam']['id']],
        ];

        foreach ($cases as [$table, $original, $column, $invalid]) {
            $record = (array) DB::table($table)->where('id', $original->id)->first();
            $snapshot = $this->rosterRecords();

            $this->rejectRosterWrite(function () use ($table, $record, $column, $invalid): void {
                if ($table === 'exam_rosters' && $column === 'exam_id') {
                    // Isolate the new ownership constraint from the existing
                    // parent-identity FK. The savepoint restores these rows.
                    DB::table('exam_roster_learners')
                        ->where('roster_id', $record['id'])->delete();
                }
                DB::table($table)->where('id', $record['id'])
                    ->update([$column => $invalid]);
            });
            $this->assertSame($snapshot, $this->rosterRecords());

            // Remove the fixture inside the savepoint to avoid unrelated
            // uniqueness failures, then attempt the invalid replacement.
            $this->rejectRosterWrite(function () use ($table, $record, $column, $invalid): void {
                DB::table($table)->where('id', $record['id'])->delete();
                $record['id'] = (string) Str::uuid();
                $record[$column] = $invalid;
                DB::table($table)->insert($record);
            });
            $this->assertSame($snapshot, $this->rosterRecords());
        }

        // Nullable stream membership remains valid.
        DB::table('exam_roster_learners')->where('id', $f['participant']->id)
            ->update(['stream_id' => null]);
        $this->assertNull(DB::table('exam_roster_learners')
            ->where('id', $f['participant']->id)->value('stream_id'));

        $participants = DB::table('exam_roster_learners')
            ->where('roster_id', $f['roster']->id)->get();
        $selections = $participants->map(fn ($participant): array => [
            'learner_id' => $participant->learner_id,
            'exam_learning_area_ids' => DB::table('exam_roster_papers')
                ->where('roster_learner_id', $participant->id)
                ->pluck('exam_learning_area_id')->unique()->values()->all(),
        ])->all();

        // Exercise the real writer with all new constraints installed.
        $result = app(ExamRosterService::class)->replace(
            $f['roster']->school_id, $f['roster']->exam_id,
            $f['roster']->created_by, (int) $f['roster']->version, $selections
        );
        $this->assertSame((int) $f['roster']->version + 1, $result['version']);

        $this->assertSame(0, DB::table('exam_roster_papers as p')
            ->join('exam_roster_learners as l', 'l.id', '=', 'p.roster_learner_id')
            ->whereColumn('p.exam_id', '<>', 'l.exam_id')->count());

        // Both the original and additional participant references retain cascade.
        $participantIds = DB::table('exam_roster_learners')
            ->where('roster_id', $f['roster']->id)->pluck('id');
        $this->assertGreaterThan(0, DB::table('exam_roster_papers')
            ->whereIn('roster_learner_id', $participantIds)->count());
        DB::table('exam_rosters')->where('id', $f['roster']->id)->delete();
        $this->assertSame(0, DB::table('exam_roster_learners')
            ->whereIn('id', $participantIds)->count());
        $this->assertSame(0, DB::table('exam_roster_papers')
            ->whereIn('roster_learner_id', $participantIds)->count());
    }

    public function test_roster_hardening_rejects_existing_paper_conflict_without_changes(): void
    {
        $f = $this->rosterIntegrityFixture();
        $this->applyReissueHardening();

        DB::table('exam_roster_papers')->where('id', $f['paper']->id)
            ->update(['paper_id' => $f['otherPaper']->id]);

        $records = $this->rosterRecords();
        $schema = $this->rosterSchema();
        $migration = $this->rosterMigration();
        $rejected = false;

        try {
            DB::transaction(fn () => $migration->up());
        } catch (\RuntimeException $exception) {
            $rejected = true;
            $this->assertStringContainsString(
                'assessment_roster_paper_chain:', $exception->getMessage()
            );
        }

        $this->assertTrue($rejected);
        $this->assertSame($records, $this->rosterRecords());
        $this->assertSame($schema, $this->rosterSchema());
        $this->assertFalse(Schema::hasColumn('exam_roster_papers', 'exam_id'));
    }

    public function test_roster_hardening_rollback_preserves_original_records_and_schema(): void
    {
        $this->rosterIntegrityFixture();
        $this->applyReissueHardening();
        $records = $this->rosterRecords();
        $schema = $this->rosterSchema();
        $migration = $this->rosterMigration();

        DB::transaction(fn () => $migration->up());
        $installed = $this->rosterSchema();
        $this->assertSame($records, $this->rosterRecords());
        $this->assertTrue(Schema::hasColumn('exam_roster_papers', 'exam_id'));

        DB::transaction(fn () => $migration->down());
        $this->assertSame($records, $this->rosterRecords());
        $this->assertSame($schema, $this->rosterSchema());
        $this->assertFalse(Schema::hasColumn('exam_roster_papers', 'exam_id'));

        DB::transaction(fn () => $migration->up());
        $this->assertSame($installed, $this->rosterSchema());
        $this->assertSame($records, $this->rosterRecords());
    }

    private function rosterIntegrityFixture(): array
    {
        $this->assertSame(config('database.connections.'.config('database.default').'.database'),
            DB::selectOne('SELECT current_database() AS name')->name);

        $exam = DB::table('exams')->where('exam_name', 'Report Card Exam')->first();
        $foreignExam = DB::table('exams')
            ->where('exam_name', 'Other School Report Exam')->first();
        $roster = DB::table('exam_rosters')->where('exam_id', $exam->id)->first();
        $participant = DB::table('exam_roster_learners')
            ->where('roster_id', $roster->id)->first();
        $paper = DB::table('exam_roster_papers')
            ->where('roster_learner_id', $participant->id)->first();
        $actor = DB::table('users')->where('id', $roster->created_by)->first();

        $foreignSchool = (object) ['id' => $foreignExam->school_id];
        $foreignActor = UserBuilder::create($foreignSchool, (object) ['id' => $actor->role_id]);
        $foreignGrade = GradeBuilder::create($foreignSchool);
        $foreignStream = StreamBuilder::create($foreignSchool, $foreignGrade);
        $foreignLearner = LearnerBuilder::create($foreignSchool, $foreignGrade, $foreignStream);

        $otherExam = (array) $exam;
        $otherExam['id'] = (string) Str::uuid();
        $otherExam['exam_name'] = 'Roster integrity '.Str::random(12);
        DB::table('exams')->insert($otherExam);

        $otherRoster = (array) $roster;
        $otherRoster['id'] = (string) Str::uuid();
        $otherRoster['exam_id'] = $otherExam['id'];
        DB::table('exam_rosters')->insert($otherRoster);

        $otherParticipant = (array) $participant;
        $otherParticipant['id'] = (string) Str::uuid();
        $otherParticipant['roster_id'] = $otherRoster['id'];
        $otherParticipant['exam_id'] = $otherExam['id'];
        DB::table('exam_roster_learners')->insert($otherParticipant);

        $otherArea = DB::table('exam_learning_areas')
            ->where('exam_id', $exam->id)
            ->where('id', '<>', $paper->exam_learning_area_id)->first();
        $otherPaper = DB::table('exam_papers')
            ->where('exam_learning_area_id', $otherArea->id)->first();

        // Use a distinct paper in the second exam so an unrelated
        // participant/paper uniqueness constraint cannot mask the mismatch.
        $areaRecord = (array) $otherArea;
        $areaRecord['id'] = (string) Str::uuid();
        $areaRecord['exam_id'] = $otherExam['id'];
        DB::table('exam_learning_areas')->insert($areaRecord);
        $otherArea = (object) $areaRecord;

        $paperRecord = (array) $otherPaper;
        $paperRecord['id'] = (string) Str::uuid();
        $paperRecord['exam_learning_area_id'] = $otherArea->id;
        DB::table('exam_papers')->insert($paperRecord);
        $otherPaper = (object) $paperRecord;

        return compact(
            'roster', 'participant', 'paper', 'foreignExam',
            'foreignActor', 'foreignGrade', 'foreignStream', 'foreignLearner',
            'otherExam', 'otherParticipant', 'otherArea', 'otherPaper'
        );
    }

    private function applyReissueHardening(): void
    {
        $migration = require database_path(
            'migrations/2026_10_29_100000_harden_assessment_reissue_tenant_integrity.php'
        );
        DB::transaction(fn () => $migration->up());
    }

    private function rosterMigration(): object
    {
        return require database_path(
            'migrations/2026_10_29_110000_harden_exam_roster_relationships.php'
        );
    }

    private function rejectRosterWrite(callable $write): void
    {
        try {
            DB::transaction($write);
            $this->fail('Expected composite roster foreign-key rejection.');
        } catch (QueryException $exception) {
            $this->assertSame('23503', $exception->errorInfo[0]);
            $this->assertStringContainsString(
                'assessment_roster_', $exception->getMessage()
            );
        }
    }

    private function rosterRecords(): array
    {
        $snapshot = [];
        foreach (['exam_rosters', 'exam_roster_learners', 'exam_roster_papers'] as $table) {
            $snapshot[$table] = DB::table($table)->orderBy('id')->get()
                ->map(function ($row) use ($table): array {
                    $record = (array) $row;
                    if ($table === 'exam_roster_papers') {
                        unset($record['exam_id']);
                    }

                    return $record;
                })->all();
        }

        return $snapshot;
    }

    private function rosterSchema(): array
    {
        return array_map(fn ($row): array => (array) $row, DB::select(<<<'SQL'
            SELECT 'constraint' AS kind, t.relname AS relation,
                   c.conname AS name, pg_get_constraintdef(c.oid) AS definition
            FROM pg_constraint c
            JOIN pg_class t ON t.oid = c.conrelid
            JOIN pg_namespace n ON n.oid = t.relnamespace
            WHERE n.nspname = 'public'
            UNION ALL
            SELECT 'index', tablename, indexname, indexdef
            FROM pg_indexes WHERE schemaname = 'public'
            ORDER BY kind, relation, name
            SQL));
    }
}
