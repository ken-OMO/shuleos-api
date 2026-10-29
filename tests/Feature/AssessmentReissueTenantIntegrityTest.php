<?php

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Support\Database\AcademicYearBuilder;
use Tests\Support\Database\GradeBuilder;
use Tests\Support\Database\LearnerBuilder;
use Tests\Support\Database\SchoolBuilder;
use Tests\Support\Database\StreamBuilder;
use Tests\Support\Database\TermBuilder;
use Tests\Support\Database\UserBuilder;

class AssessmentReissueTenantIntegrityTest extends ReportCardTest
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

    public function test_database_rejects_cross_school_and_cross_exam_references(): void
    {
        $this->assertSame(
            config('database.connections.'.config('database.default').'.database'),
            DB::selectOne('SELECT current_database() AS name')->name
        );

        $exam = DB::table('exams')->where('exam_name', 'Report Card Exam')->first();
        $this->assertNotNull($exam);

        $actor = DB::table('users')->where('school_id', $exam->school_id)->first();
        $learner = DB::table('learners')->where('school_id', $exam->school_id)->first();
        $this->assertNotNull($actor);
        $this->assertNotNull($learner);

        $school = (object) ['id' => $exam->school_id];
        $role = (object) ['id' => $actor->role_id];
        $reviewer = UserBuilder::create($school, $role);

        $foreignSchool = SchoolBuilder::create();
        $foreignActor = UserBuilder::create($foreignSchool, $role);
        $foreignGrade = GradeBuilder::create($foreignSchool);
        $foreignStream = StreamBuilder::create($foreignSchool, $foreignGrade);
        $foreignLearner = LearnerBuilder::create(
            $foreignSchool, $foreignGrade, $foreignStream
        );
        $foreignYear = AcademicYearBuilder::create($foreignSchool);
        $foreignTerm = TermBuilder::create($foreignSchool, $foreignYear);

        $otherExam = (array) $exam;
        $otherExam['id'] = (string) Str::uuid();
        $otherExam['exam_name'] = 'Same school exam '.Str::random(12);
        DB::table('exams')->insert($otherExam);

        $foreignExam = (array) $exam;
        $foreignExam['id'] = (string) Str::uuid();
        $foreignExam['exam_name'] = 'Foreign exam '.Str::random(12);
        $foreignExam['school_id'] = $foreignSchool->id;
        $foreignExam['academic_year_id'] = $foreignYear->id;
        $foreignExam['term_id'] = $foreignTerm->id;
        DB::table('exams')->insert($foreignExam);

        $request = [
            'id' => (string) Str::uuid(),
            'school_id' => $school->id,
            'exam_id' => $exam->id,
            'reason' => 'Database integrity fixture.',
            'proposed_changes' => '[]',
            'proposal_sha256' => str_repeat('a', 64),
            'source_sha256' => str_repeat('b', 64),
            'status' => 'rejected',
            'requested_by' => $actor->id,
            'decided_by' => $reviewer->id,
            'reviewed_by' => $reviewer->id,
            'published_by' => $reviewer->id,
        ];
        DB::table('assessment_reissue_requests')->insert($request);

        $sameSchoolRequest = array_replace($request, [
            'id' => (string) Str::uuid(),
            'exam_id' => $otherExam['id'],
        ]);
        DB::table('assessment_reissue_requests')->insert($sameSchoolRequest);

        $foreignRequest = array_replace($request, [
            'id' => (string) Str::uuid(),
            'school_id' => $foreignSchool->id,
            'exam_id' => $foreignExam['id'],
            'requested_by' => $foreignActor->id,
            'decided_by' => null,
            'reviewed_by' => null,
            'published_by' => null,
        ]);
        DB::table('assessment_reissue_requests')->insert($foreignRequest);

        $event = [
            'id' => (string) Str::uuid(),
            'request_id' => $request['id'],
            'school_id' => $school->id,
            'actor_id' => $actor->id,
            'action' => 'submitted',
            'reason' => 'Database integrity fixture.',
            'details' => '{}',
            'created_at' => now(),
        ];
        $draft = [
            'id' => (string) Str::uuid(),
            'request_id' => $request['id'],
            'school_id' => $school->id,
            'exam_id' => $exam->id,
            'learner_id' => $learner->id,
            'output_type' => 'report_card',
            'output_id' => (string) Str::uuid(),
            'revision_number' => 2,
            'source_sha256' => str_repeat('b', 64),
            'payload' => '{}',
            'payload_sha256' => str_repeat('c', 64),
            'prepared_by' => $actor->id,
            'created_at' => now(),
        ];
        $version = [
            'id' => (string) Str::uuid(),
            'school_id' => $school->id,
            'exam_id' => $exam->id,
            'learner_id' => $learner->id,
            'output_type' => 'report_card',
            'output_id' => (string) Str::uuid(),
            'revision_number' => 2,
            'reissue_request_id' => $request['id'],
            'payload' => '{}',
            'payload_sha256' => str_repeat('c', 64),
            'published_at' => now(),
            'published_by' => $actor->id,
            'recorded_at' => now(),
        ];

        foreach ([
            'assessment_reissue_events' => $event,
            'assessment_reissue_drafts' => $draft,
            'assessment_publication_versions' => $version,
        ] as $table => $record) {
            DB::table($table)->insert($record);
        }

        // Apply to populated valid records inside the outer test transaction.
        $migration = require database_path(
            'migrations/2026_10_29_100000_harden_assessment_reissue_tenant_integrity.php'
        );
        $migration->up();

        $expectedConstraints = [
            'assessment_request_exam_tenant_fk',
            'assessment_event_request_tenant_fk',
            'assessment_draft_request_exam_fk',
            'assessment_version_request_exam_fk',
            'assessment_draft_exam_tenant_fk',
            'assessment_version_exam_tenant_fk',
            'assessment_draft_learner_tenant_fk',
            'assessment_version_learner_tenant_fk',
            'assessment_request_requester_tenant_fk',
            'assessment_request_decider_tenant_fk',
            'assessment_request_reviewer_tenant_fk',
            'assessment_request_publisher_tenant_fk',
            'assessment_event_actor_tenant_fk',
            'assessment_draft_preparer_tenant_fk',
            'assessment_version_publisher_tenant_fk',
        ];

        $installedConstraints = DB::table('pg_constraint as fk')
            ->join('pg_class as relation', 'relation.oid', '=', 'fk.conrelid')
            ->join('pg_namespace as namespace', 'namespace.oid', '=', 'relation.relnamespace')
            ->where('namespace.nspname', 'public')
            ->whereIn('fk.conname', $expectedConstraints)
            ->selectRaw(
                'fk.conname, fk.contype, '
                .'CASE WHEN fk.convalidated THEN 1 ELSE 0 END AS validated, '
                .'fk.confdeltype, fk.confupdtype, '
                .'cardinality(fk.conkey) AS column_count'
            )->get();

        $this->assertCount(15, $installedConstraints);
        $this->assertEqualsCanonicalizing(
            $expectedConstraints,
            $installedConstraints->pluck('conname')->all()
        );

        foreach ($installedConstraints as $constraint) {
            $this->assertSame('f', $constraint->contype);
            $this->assertSame(1, (int) $constraint->validated);
            $this->assertSame('r', $constraint->confdeltype);
            $this->assertSame('r', $constraint->confupdtype);
            $this->assertSame(
                in_array($constraint->conname, [
                    'assessment_draft_request_exam_fk',
                    'assessment_version_request_exam_fk',
                ], true) ? 3 : 2,
                (int) $constraint->column_count
            );
        }

        $cases = [
            ['assessment_reissue_requests', $request, 'exam_id', $foreignExam['id'], 'assessment_request_exam_tenant_fk'],
            ['assessment_reissue_events', $event, 'request_id', $foreignRequest['id'], 'assessment_event_request_tenant_fk'],
            ['assessment_reissue_drafts', $draft, 'request_id', $foreignRequest['id'], 'assessment_draft_request_exam_fk'],
            ['assessment_publication_versions', $version, 'reissue_request_id', $foreignRequest['id'], 'assessment_version_request_exam_fk'],
            ['assessment_reissue_drafts', $draft, 'exam_id', $foreignExam['id'], ['assessment_draft_exam_tenant_fk', 'assessment_draft_request_exam_fk']],
            ['assessment_publication_versions', $version, 'exam_id', $foreignExam['id'], ['assessment_version_exam_tenant_fk', 'assessment_version_request_exam_fk']],
            ['assessment_reissue_drafts', $draft, 'learner_id', $foreignLearner->id, 'assessment_draft_learner_tenant_fk'],
            ['assessment_publication_versions', $version, 'learner_id', $foreignLearner->id, 'assessment_version_learner_tenant_fk'],
        ];

        foreach ([
            ['assessment_reissue_requests', $request, 'requested_by', 'request_requester'],
            ['assessment_reissue_requests', $request, 'decided_by', 'request_decider'],
            ['assessment_reissue_requests', $request, 'reviewed_by', 'request_reviewer'],
            ['assessment_reissue_requests', $request, 'published_by', 'request_publisher'],
            ['assessment_reissue_events', $event, 'actor_id', 'event_actor'],
            ['assessment_reissue_drafts', $draft, 'prepared_by', 'draft_preparer'],
            ['assessment_publication_versions', $version, 'published_by', 'version_publisher'],
        ] as [$table, $record, $column, $label]) {
            $cases[] = [
                $table, $record, $column, $foreignActor->id,
                'assessment_'.$label.'_tenant_fk',
            ];
        }

        // An otherwise valid same-school reference must still match the exam.
        $cases[] = [
            'assessment_reissue_drafts', $draft, 'request_id',
            $sameSchoolRequest['id'], 'assessment_draft_request_exam_fk',
        ];
        $cases[] = [
            'assessment_publication_versions', $version, 'reissue_request_id',
            $sameSchoolRequest['id'], 'assessment_version_request_exam_fk',
        ];

        foreach ($cases as [$table, $record, $column, $invalidId, $constraint]) {
            // Each insert must succeed before changing exactly one reference.
            $valid = $record;
            $valid['id'] = (string) Str::uuid();
            if (array_key_exists('output_id', $valid)) {
                $valid['output_id'] = (string) Str::uuid();
            }
            DB::table($table)->insert($valid);

            $this->rejectReference(
                fn () => DB::table($table)->where('id', $valid['id'])
                    ->update([$column => $invalidId]),
                $constraint
            );
            $this->assertSame(
                $valid[$column],
                DB::table($table)->where('id', $valid['id'])->value($column)
            );

            $invalid = $valid;
            $invalid['id'] = (string) Str::uuid();
            if (array_key_exists('output_id', $invalid)) {
                $invalid['output_id'] = (string) Str::uuid();
            }
            $invalid[$column] = $invalidId;

            $this->rejectReference(
                fn () => DB::table($table)->insert($invalid),
                $constraint
            );
            $this->assertFalse(
                DB::table($table)->where('id', $invalid['id'])->exists()
            );
        }

        // Optional actors and revision-one request links remain nullable.
        DB::table('assessment_reissue_requests')->where('id', $request['id'])->update([
            'decided_by' => null,
            'reviewed_by' => null,
            'published_by' => null,
        ]);
        DB::table('assessment_publication_versions')->where('id', $version['id'])->update([
            'revision_number' => 1,
            'reissue_request_id' => null,
            'published_by' => null,
        ]);
        $this->assertNull(
            DB::table('assessment_publication_versions')
                ->where('id', $version['id'])->value('reissue_request_id')
        );
    }

    public function test_migration_rejects_existing_conflicts_without_changes(): void
    {
        $fixture = $this->integrityLifecycleFixture();
        $request = $fixture['request'];

        // Individually valid IDs, but the exam belongs to another school.
        // Existing single-column foreign keys permit this relationship.
        $foreignSchool = SchoolBuilder::create();
        $foreignActor = UserBuilder::create(
            $foreignSchool,
            (object) ['id' => $fixture['actor']->role_id]
        );

        DB::table('assessment_reissue_requests')
            ->where('id', $request['id'])->update([
                'school_id' => $foreignSchool->id,
                'requested_by' => $foreignActor->id,
            ]);

        $beforeRecords = $this->integrityRecordSnapshot();
        $beforeSchema = $this->integritySchemaSnapshot();
        $migration = require database_path(
            'migrations/2026_10_29_100000_harden_assessment_reissue_tenant_integrity.php'
        );

        $rejected = false;

        try {
            DB::transaction(fn () => $migration->up());
        } catch (\RuntimeException $exception) {
            $rejected = true;
            $this->assertStringContainsString(
                'assessment_request_exam_tenant_fk:',
                $exception->getMessage()
            );
            $this->assertStringContainsString(
                'conflicting records',
                $exception->getMessage()
            );
        }

        $this->assertTrue($rejected, 'Expected existing conflict rejection.');
        $this->assertSame($beforeRecords, $this->integrityRecordSnapshot());
        $this->assertSame($beforeSchema, $this->integritySchemaSnapshot());

        // The rejected record remains available for deliberate remediation.
        $this->assertSame(
            $foreignSchool->id,
            DB::table('assessment_reissue_requests')
                ->where('id', $request['id'])->value('school_id')
        );
    }

    public function test_migration_rollback_preserves_records_and_existing_schema(): void
    {
        $this->integrityLifecycleFixture();

        $beforeRecords = $this->integrityRecordSnapshot();
        $beforeSchema = $this->integritySchemaSnapshot();
        $migration = require database_path(
            'migrations/2026_10_29_100000_harden_assessment_reissue_tenant_integrity.php'
        );

        DB::transaction(fn () => $migration->up());

        $afterUp = $this->integritySchemaSnapshot();
        $this->assertSame($beforeRecords, $this->integrityRecordSnapshot());

        $beforeConstraintNames = array_column($beforeSchema['constraints'], 'identity');
        $afterConstraintNames = array_column($afterUp['constraints'], 'identity');
        $beforeIndexNames = array_column($beforeSchema['indexes'], 'identity');
        $afterIndexNames = array_column($afterUp['indexes'], 'identity');

        // Fifteen foreign keys and three unique constraints/indexes.
        $this->assertCount(
            18, array_diff($afterConstraintNames, $beforeConstraintNames)
        );
        $this->assertCount(
            3, array_diff($afterIndexNames, $beforeIndexNames)
        );
        $this->assertSame(
            [], array_values(array_diff($beforeConstraintNames, $afterConstraintNames))
        );
        $this->assertSame(
            [], array_values(array_diff($beforeIndexNames, $afterIndexNames))
        );

        DB::transaction(fn () => $migration->down());

        $this->assertSame($beforeRecords, $this->integrityRecordSnapshot());
        $this->assertSame($beforeSchema, $this->integritySchemaSnapshot());

        // A second application also works after rollback.
        DB::transaction(fn () => $migration->up());
        $this->assertSame($afterUp, $this->integritySchemaSnapshot());
        $this->assertSame($beforeRecords, $this->integrityRecordSnapshot());
    }

    private function integrityLifecycleFixture(): array
    {
        $this->assertSame(
            config('database.connections.'.config('database.default').'.database'),
            DB::selectOne('SELECT current_database() AS name')->name
        );

        $exam = DB::table('exams')
            ->where('exam_name', 'Report Card Exam')->first();
        $this->assertNotNull($exam);
        $actor = DB::table('users')
            ->where('school_id', $exam->school_id)->first();
        $learner = DB::table('learners')
            ->where('school_id', $exam->school_id)->first();
        $this->assertNotNull($actor);
        $this->assertNotNull($learner);

        $request = [
            'id' => (string) Str::uuid(),
            'school_id' => $exam->school_id,
            'exam_id' => $exam->id,
            'reason' => 'Migration lifecycle fixture.',
            'proposed_changes' => '[]',
            'proposal_sha256' => str_repeat('a', 64),
            'source_sha256' => str_repeat('b', 64),
            'status' => 'rejected',
            'requested_by' => $actor->id,
        ];
        DB::table('assessment_reissue_requests')->insert($request);

        DB::table('assessment_reissue_events')->insert([
            'id' => (string) Str::uuid(),
            'request_id' => $request['id'],
            'school_id' => $exam->school_id,
            'actor_id' => $actor->id,
            'action' => 'submitted',
            'reason' => 'Migration lifecycle fixture.',
            'details' => '{}',
            'created_at' => now(),
        ]);

        DB::table('assessment_reissue_drafts')->insert([
            'id' => (string) Str::uuid(),
            'request_id' => $request['id'],
            'school_id' => $exam->school_id,
            'exam_id' => $exam->id,
            'learner_id' => $learner->id,
            'output_type' => 'report_card',
            'output_id' => (string) Str::uuid(),
            'revision_number' => 2,
            'source_sha256' => str_repeat('b', 64),
            'payload' => '{}',
            'payload_sha256' => str_repeat('c', 64),
            'prepared_by' => $actor->id,
            'created_at' => now(),
        ]);

        DB::table('assessment_publication_versions')->insert([
            'id' => (string) Str::uuid(),
            'school_id' => $exam->school_id,
            'exam_id' => $exam->id,
            'learner_id' => $learner->id,
            'output_type' => 'report_card',
            'output_id' => (string) Str::uuid(),
            'revision_number' => 2,
            'reissue_request_id' => $request['id'],
            'payload' => '{}',
            'payload_sha256' => str_repeat('c', 64),
            'published_at' => now(),
            'published_by' => $actor->id,
            'recorded_at' => now(),
        ]);

        return compact('request', 'actor');
    }

    private function integrityRecordSnapshot(): array
    {
        $snapshot = [];

        foreach ([
            'assessment_reissue_requests',
            'assessment_reissue_events',
            'assessment_reissue_drafts',
            'assessment_publication_versions',
        ] as $table) {
            $snapshot[$table] = DB::table($table)->orderBy('id')->get()
                ->map(fn ($row): array => (array) $row)->all();
        }

        return $snapshot;
    }

    private function integritySchemaSnapshot(): array
    {
        $constraints = DB::select(<<<'SQL'
            SELECT t.relname || '.' || c.conname AS identity,
                   c.contype::text AS type,
                   CASE WHEN c.convalidated THEN 1 ELSE 0 END AS validated,
                   pg_get_constraintdef(c.oid) AS definition
            FROM pg_constraint c
            JOIN pg_class t ON t.oid = c.conrelid
            JOIN pg_namespace n ON n.oid = t.relnamespace
            WHERE n.nspname = 'public'
            ORDER BY t.relname, c.conname
            SQL);

        $indexes = DB::select(<<<'SQL'
            SELECT tablename || '.' || indexname AS identity,
                   indexdef AS definition
            FROM pg_indexes
            WHERE schemaname = 'public'
            ORDER BY tablename, indexname
            SQL);

        return [
            'constraints' => array_map(fn ($row): array => (array) $row, $constraints),
            'indexes' => array_map(fn ($row): array => (array) $row, $indexes),
        ];
    }

    private function rejectReference(callable $write, string|array $constraint): void
    {
        try {
            // A savepoint restores PostgreSQL after an expected constraint error.
            DB::transaction($write);
            $this->fail('Expected foreign-key rejection: '.implode(', ', (array) $constraint));
        } catch (QueryException $exception) {
            $this->assertSame('23503', $exception->errorInfo[0]);
            $matched = false;
            foreach ((array) $constraint as $name) {
                $matched = $matched || str_contains($exception->getMessage(), $name);
            }
            $this->assertTrue(
                $matched,
                'Expected one of: '.implode(', ', (array) $constraint)
                .PHP_EOL.$exception->getMessage()
            );
        }
    }
}
