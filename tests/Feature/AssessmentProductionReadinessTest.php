<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Assessment\ExamRosterService;
use App\Services\Assessment\MeritListService;
use App\Services\Assessment\ResultProcessingService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Tests\Support\ExamBenchmarkFixture;
use Tests\TestCase;

class AssessmentProductionReadinessTest extends TestCase
{
    use DatabaseTransactions;

    private array $fixture;

    protected function setUp(): void
    {
        parent::setUp();

        config(['jwt.secret' => str_repeat('assessment-readiness-test-', 3)]);
        $this->fixture = ExamBenchmarkFixture::create(251);
    }

    private function actor(bool $grantPermission): User
    {
        $user = User::withoutGlobalScopes()
            ->findOrFail($this->fixture['actor_id']);

        $permission = DB::table('permissions')
            ->where('permission_name', 'generate_report_cards')->value('id');

        $this->assertNotNull(
            $permission,
            'Run the generate_report_cards permission migration.'
        );

        if ($grantPermission) {
            DB::table('role_permissions')->insert([
                'id' => (string) Str::uuid(),
                'role_id' => $user->role_id,
                'permission_id' => $permission,
            ]);
        }

        return $user->load('role');
    }

    public function test_processing_avoids_redundant_context_reads_and_preserves_identity(): void
    {
        $learnerId = $this->fixture['learner_ids'][0];
        $areaId = $this->fixture['exam_learning_area_ids'][0];
        $learningAreaId = DB::table('exam_learning_areas')
            ->where('id', $areaId)->value('learning_area_id');

        $before = DB::table('learning_area_results')
            ->where('school_id', $this->fixture['school_id'])
            ->where('exam_id', $this->fixture['exam_id'])
            ->where('learner_id', $learnerId)
            ->where('learning_area_id', $learningAreaId)->first();

        $connection = DB::connection();
        $wasLogging = $connection->logging();

        try {
            $connection->enableQueryLog();
            $connection->flushQueryLog();

            $result = app(ResultProcessingService::class)
                ->process(
                    $this->fixture['school_id'], $areaId,
                    $learnerId, $this->fixture['actor_id']
                );

            $queries = $connection->getQueryLog();
        } finally {
            $connection->flushQueryLog();
            if (! $wasLogging) {
                $connection->disableQueryLog();
            }
        }

        $this->assertSame($before->id, $result->id);
        $this->assertSame('processed', $result->processing_status);
        $this->assertSame(
            json_decode($before->grading_snapshot, true, 512, JSON_THROW_ON_ERROR),
            $result->grading_snapshot
        );
        // Includes school lifecycle locks retained through processing.
        $this->assertLessThanOrEqual(33, count($queries));

        $schoolLocks = collect($queries)->filter(
            fn ($query) => str_contains($query['query'], 'from "schools"')
                && str_contains($query['query'], 'for share')
        )->count();
        // Processing and nested grading both acquire school before exam.
        $this->assertSame(2, $schoolLocks);

        $educationReads = collect($queries)->filter(
            fn ($query) => str_contains($query['query'], 'from "education_levels"')
        )->count();
        // Two membership resolutions and the returned learner relationship.
        $this->assertSame(3, $educationReads);

        foreach (['exam', 'learner', 'learningArea',
            'gradingSystem', 'gradingScale', 'processedBy'] as $relation) {
            $this->assertTrue($result->relationLoaded($relation));
        }
        $this->assertTrue($result->learner->relationLoaded('grade'));
        $this->assertTrue($result->learner->grade->relationLoaded('educationLevel'));
    }

    public function test_permission_migration_preserves_identity_metadata_and_grants(): void
    {
        $user = $this->actor(true);

        $permission = DB::table('permissions')
            ->where('permission_name', 'generate_report_cards')->first();

        $this->assertNotNull($permission);

        DB::table('permissions')->where('id', $permission->id)->update([
            'module_name' => 'existing_module',
            'description' => 'Preserve existing permission metadata.',
        ]);

        $before = (array) DB::table('permissions')
            ->where('id', $permission->id)->first();

        $grantsBefore = DB::table('role_permissions')
            ->where('permission_id', $permission->id)
            ->orderBy('id')->get()
            ->map(fn ($row) => (array) $row)->all();

        $this->assertTrue(DB::table('role_permissions')
            ->where('role_id', $user->role_id)
            ->where('permission_id', $permission->id)->exists());

        $migration = require database_path(
            'migrations/2026_10_22_170000_add_generate_report_cards_permission.php'
        );

        $migration->up();
        $migration->up();
        $migration->down();

        $this->assertSame(1, DB::table('permissions')
            ->where('permission_name', 'generate_report_cards')->count());

        $this->assertSame(
            $before,
            (array) DB::table('permissions')
                ->where('id', $permission->id)->first()
        );

        $this->assertSame(
            $grantsBefore,
            DB::table('role_permissions')
                ->where('permission_id', $permission->id)
                ->orderBy('id')->get()
                ->map(fn ($row) => (array) $row)->all()
        );
    }

    public function test_roster_admission_accepts_2000_and_rejects_2001_without_changes(): void
    {
        $schoolId = $this->fixture['school_id'];
        $examId = $this->fixture['exam_id'];
        $actorId = $this->fixture['actor_id'];

        // Remove synthetic admission so the real service establishes the roster.
        DB::table('exam_rosters')
            ->where('school_id', $schoolId)->where('exam_id', $examId)->delete();

        $source = DB::table('learners')
            ->where('id', $this->fixture['learner_ids'][0])->first();

        $ids = $this->fixture['learner_ids'];
        $rows = [];

        while (count($ids) < 2001) {
            $id = (string) Str::uuid();
            $ids[] = $id;
            $rows[] = [
                'id' => $id,
                'school_id' => $schoolId,
                'admission_no' => 'ADMISSION-'.str_replace('-', '', $id),
                'first_name' => 'Admission',
                'last_name' => 'Boundary',
                'grade_id' => $source->grade_id,
                'stream_id' => $source->stream_id,
                'active' => true,
                'is_deleted' => false,
                'portal_enabled' => false,
            ];
        }

        foreach (array_chunk($rows, 100) as $chunk) {
            DB::table('learners')->insert($chunk);
        }

        $areaId = $this->fixture['exam_learning_area_ids'][0];
        $selections = array_map(fn ($id) => [
            'learner_id' => $id,
            'exam_learning_area_ids' => [$areaId],
        ], $ids);

        $service = app(ExamRosterService::class);
        $result = $service->replace(
            $schoolId, $examId, $actorId, 0,
            array_slice($selections, 0, 2000)
        );

        $this->assertSame(1, $result['version']);
        $this->assertSame(2000, $result['learner_count']);

        $snapshot = function () use ($schoolId, $examId): array {
            $roster = DB::table('exam_rosters')
                ->where('school_id', $schoolId)->where('exam_id', $examId)->first();

            $participants = DB::table('exam_roster_learners')
                ->where('roster_id', $roster->id)->orderBy('id')->get();

            return [
                'roster' => (array) $roster,
                'participants' => $participants
                    ->map(fn ($row) => (array) $row)->all(),
                'papers' => DB::table('exam_roster_papers')
                    ->whereIn('roster_learner_id', $participants->pluck('id'))
                    ->orderBy('id')->get()
                    ->map(fn ($row) => (array) $row)->all(),
            ];
        };

        $before = $snapshot();
        $this->assertCount(2000, $before['participants']);
        $this->assertCount(4000, $before['papers']);
        $this->assertSame(
            collect(array_slice($ids, 0, 2000))->sort()->values()->all(),
            collect($before['participants'])->pluck('learner_id')
                ->sort()->values()->all()
        );

        try {
            $service->replace($schoolId, $examId, $actorId, 1, $selections);
            $this->fail('Admission above 2000 must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('learners', $exception->errors());
        }

        $this->assertSame($before, $snapshot());

        try {
            $service->replace(
                $schoolId, $examId, $actorId, 0,
                array_slice($selections, 0, 2000)
            );
            $this->fail('A stale roster version must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('version', $exception->errors());
        }

        $this->assertSame($before, $snapshot());

        $permission = DB::table('permissions')
            ->where('permission_name', 'create_exam')->value('id');
        $this->assertNotNull($permission, 'Roster API requires create_exam provisioning.');

        $user = User::withoutGlobalScopes()->findOrFail($actorId);
        DB::table('role_permissions')->insert([
            'id' => (string) Str::uuid(),
            'role_id' => $user->role_id,
            'permission_id' => $permission,
            'created_at' => now(),
        ]);

        $token = JWTAuth::fromUser($user->load('role'));
        $url = '/api/exams/'.$examId.'/roster';

        $this->withToken($token)->putJson($url, [
            'version' => 1,
            'learners' => $selections,
        ])->assertUnprocessable()->assertJsonValidationErrors('learners');

        $this->assertSame($before, $snapshot());

        $this->withToken($token)->putJson($url, [
            'version' => 1,
            'learners' => array_slice($selections, 0, 2000),
        ])->assertOk()
            ->assertJsonPath('data.version', 2)
            ->assertJsonPath('data.learner_count', 2000);

        $after = $snapshot();
        $this->assertSame($before['roster']['id'], $after['roster']['id']);
        $this->assertCount(2000, $after['participants']);
        $this->assertCount(4000, $after['papers']);

        foreach (['id', 'learner_id', 'grade_id', 'stream_id', 'created_at'] as $column) {
            $this->assertSame(
                array_column($before['participants'], $column),
                array_column($after['participants'], $column)
            );
        }
    }

    public function test_roster_admission_batches_participant_and_paper_inserts(): void
    {
        $connection = DB::connection();
        $wasLogging = $connection->logging();

        try {
            $connection->enableQueryLog();
            $connection->flushQueryLog();

            $result = $this->replaceReadinessRoster();
            $queries = collect($connection->getQueryLog());
        } finally {
            $connection->flushQueryLog();
            if (! $wasLogging) {
                $connection->disableQueryLog();
            }
        }

        $this->assertSame(251, $result['learner_count']);
        $this->assertSame(2, $result['version']);

        foreach ([
            'exam_roster_learners' => 2,
            'exam_roster_papers' => 7,
        ] as $table => $expected) {
            $this->assertSame(
                $expected,
                $queries->filter(fn ($query) => str_starts_with(
                    $query['query'], 'insert into "'.$table.'"'
                ))->count()
            );
        }

        $state = $this->readinessRosterState();
        $this->assertCount(251, $state['participants']);
        $this->assertCount(3012, $state['papers']);
    }

    public function test_roster_admission_second_batch_failure_restores_original_roster(): void
    {
        $before = $this->readinessRosterState();
        $constraint = 'readiness_'.str_replace('-', '', (string) Str::uuid());
        $pdo = DB::connection()->getPdo();
        $exam = $pdo->quote($this->fixture['exam_id']);
        $lastLearner = $pdo->quote($this->fixture['learner_ids'][250]);

        // Existing rows remain valid; reinserting learner 251 must fail.
        DB::statement(
            'ALTER TABLE exam_roster_learners ADD CONSTRAINT '.$constraint.
            ' CHECK (exam_id <> '.$exam.'::uuid OR learner_id <> '.
            $lastLearner.'::uuid) NOT VALID'
        );

        try {
            try {
                $this->replaceReadinessRoster();
                $this->fail('The injected second-batch failure must occur.');
            } catch (QueryException $exception) {
                $this->assertSame('23514', $exception->errorInfo[0]);
                $this->assertStringContainsString(
                    $constraint, $exception->getMessage()
                );
            }

            $this->assertSame($before, $this->readinessRosterState());
        } finally {
            DB::statement(
                'ALTER TABLE exam_roster_learners DROP CONSTRAINT '.$constraint
            );
        }

        $retry = $this->replaceReadinessRoster();
        $this->assertSame(2, $retry['version']);
        $this->assertSame(251, $retry['learner_count']);

        $after = $this->readinessRosterState();
        $this->assertCount(251, $after['participants']);
        $this->assertCount(3012, $after['papers']);
        $this->assertSame(
            array_column($before['participants'], 'id'),
            array_column($after['participants'], 'id')
        );
    }

    private function replaceReadinessRoster(): array
    {
        return app(ExamRosterService::class)->replace(
            $this->fixture['school_id'],
            $this->fixture['exam_id'],
            $this->fixture['actor_id'],
            1,
            array_map(fn ($id) => [
                'learner_id' => $id,
                'exam_learning_area_ids' => $this->fixture['exam_learning_area_ids'],
            ], $this->fixture['learner_ids'])
        );
    }

    private function readinessRosterState(): array
    {
        $roster = DB::table('exam_rosters')
            ->where('school_id', $this->fixture['school_id'])
            ->where('exam_id', $this->fixture['exam_id'])->first();

        $participants = DB::table('exam_roster_learners')
            ->where('roster_id', $roster->id)->orderBy('id')->get();

        return [
            'roster' => (array) $roster,
            'participants' => $participants
                ->map(fn ($row) => (array) $row)->all(),
            'papers' => DB::table('exam_roster_papers')
                ->whereIn('roster_learner_id', $participants->pluck('id'))
                ->orderBy('id')->get()
                ->map(fn ($row) => (array) $row)->all(),
        ];
    }

    private function generateMerits(): void
    {
        app(MeritListService::class)->generate(
            $this->fixture['school_id'],
            $this->fixture['exam_id'],
            null, null, $this->fixture['actor_id']
        );
    }

    public function test_generation_requires_authentication(): void
    {
        $this->postJson('/api/report-cards/generate', [
            'exam_id' => $this->fixture['exam_id'],
            'response_mode' => 'summary',
        ])->assertUnauthorized();

        $this->assertSame(0, DB::table('report_cards')
            ->where('school_id', $this->fixture['school_id'])->count());
    }

    public function test_generation_requires_report_card_permission(): void
    {
        $actor = $this->actor(false);

        $this->withToken(JWTAuth::fromUser($actor))
            ->postJson('/api/report-cards/generate', [
                'exam_id' => $this->fixture['exam_id'],
                'response_mode' => 'summary',
            ])->assertForbidden();

        $this->assertSame(0, DB::table('report_cards')
            ->where('school_id', $this->fixture['school_id'])->count());
    }

    public function test_authenticated_summary_details_and_pdf_across_batches(): void
    {
        $this->generateMerits();
        $actor = $this->actor(true);

        $this->withToken(JWTAuth::fromUser($actor))
            ->postJson('/api/report-cards/generate', [
                'exam_id' => $this->fixture['exam_id'],
                'response_mode' => 'summary',
            ])
            ->assertOk()
            ->assertJsonPath('data.exam_id', $this->fixture['exam_id'])
            ->assertJsonPath('data.generated_count', 251)
            ->assertJsonPath('data.status', 'generated');

        $cards = DB::table('report_cards')
            ->where('school_id', $this->fixture['school_id'])
            ->where('exam_id', $this->fixture['exam_id'])
            ->orderBy('learner_id')->get();

        $this->assertCount(251, $cards);

        foreach ([$cards->first(), $cards->last()] as $card) {
            $this->getJson('/api/report-cards/'.$card->id)
                ->assertOk()
                ->assertJsonPath('data.id', $card->id)
                ->assertJsonCount(6, 'data.learning_areas');

            $pdf = $this->get('/api/report-cards/'.$card->id.'/pdf');
            $pdf->assertOk()->assertHeader('content-type', 'application/pdf');
            $this->assertStringStartsWith('%PDF', $pdf->getContent());
        }

        $download = $this->get(
            '/api/report-cards/'.$cards->last()->id.'/pdf/download'
        );
        $download->assertOk()
            ->assertHeader('content-type', 'application/pdf');
        $this->assertStringContainsString(
            'attachment', $download->headers->get('content-disposition')
        );
    }

    public function test_authenticated_generation_cannot_use_another_schools_exam(): void
    {
        $foreign = ExamBenchmarkFixture::create(1);
        $actor = $this->actor(true);

        $response = $this->withToken(JWTAuth::fromUser($actor))
            ->postJson('/api/report-cards/generate', [
                'school_id' => $foreign['school_id'],
                'exam_id' => $foreign['exam_id'],
                'response_mode' => 'summary',
            ]);

        $this->assertContains($response->status(), [403, 404, 422]);
        $this->assertSame(0, DB::table('report_cards')
            ->whereIn('school_id', [
                $this->fixture['school_id'], $foreign['school_id'],
            ])->count());
    }
}
