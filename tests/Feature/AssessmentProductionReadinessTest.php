<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Assessment\MeritListService;
use App\Services\Assessment\ResultProcessingService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
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
        $this->assertLessThanOrEqual(32, count($queries));

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
