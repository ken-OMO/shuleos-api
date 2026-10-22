<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Assessment\MeritListService;
use App\Services\Assessment\ReportCardService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Tests\Support\ExamBenchmarkFixture;
use Tests\TestCase;

class AssessmentReadOnlyAccessTest extends TestCase
{
    use DatabaseTransactions;

    private array $fixture;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        config(['jwt.secret' => str_repeat('assessment-read-only-test-', 3)]);
        $this->fixture = ExamBenchmarkFixture::create(1);

        $user = User::withoutGlobalScopes()
            ->findOrFail($this->fixture['actor_id']);

        foreach (['generate_report_cards', 'create_exam'] as $name) {
            $permission = DB::table('permissions')
                ->where('permission_name', $name)->value('id');
            $this->assertNotNull($permission);

            DB::table('role_permissions')->insert([
                'id' => (string) Str::uuid(),
                'role_id' => $user->role_id,
                'permission_id' => $permission,
            ]);
        }

        $this->token = JWTAuth::fromUser($user->load('role'));
    }

    public function test_read_only_rejects_assessment_and_teacher_mark_writes_without_changes(): void
    {
        $this->setSchoolState('read_only');
        $before = $this->assessmentState();
        $exam = $this->fixture['exam_id'];
        $area = $this->fixture['exam_learning_area_ids'][0];
        $paper = DB::table('exam_papers')
            ->where('exam_learning_area_id', $area)->value('id');
        $result = DB::table('exam_results')
            ->where('exam_id', $exam)->value('id');
        $batch = (string) Str::uuid();

        $requests = [
            ['POST', '/api/assessment-types', []],
            ['POST', '/api/assessment-registrations', []],
            ['POST', '/api/exams', []],
            ['PUT', '/api/exams/'.$exam, []],
            ['DELETE', '/api/exams/'.$exam, []],
            ['PUT', '/api/exams/'.$exam.'/roster', [
                'version' => 1,
                'learners' => [[
                    'learner_id' => $this->fixture['learner_ids'][0],
                    'exam_learning_area_ids' => [$area],
                ]],
            ]],
            ['POST', '/api/exam-learning-areas', []],
            ['POST', '/api/exam-papers', []],
            ['PUT', '/api/exam-results/'.$result, []],
            ['POST', '/api/learning-area-results/process', []],
            ['POST', '/api/mark-entry-permissions', []],
            ['POST', '/api/merit-lists/generate', ['exam_id' => $exam]],
            ['POST', '/api/merit-lists/publish', ['exam_id' => $exam]],
            ['POST', '/api/report-cards/generate', [
                'exam_id' => $exam, 'response_mode' => 'summary',
            ]],
            ['POST', '/api/report-cards/publish', ['exam_id' => $exam]],
            ['PATCH', '/api/report-cards/'.$batch.'/comments', []],
            ['POST', '/api/teacher/marks-entry/'.$paper, []],
            ['POST', '/api/teacher/marks-entry/'.$paper.'/submit', []],
            ['POST', '/api/teacher/marks-entry/batches/'.$batch.'/submit', []],
            ['POST', '/api/teacher/marks-entry/batches/'.$batch.'/resubmit', []],
            ['POST', '/api/teacher/marks-entry/batches/'.$batch.'/correction-request', []],
            ['POST', '/api/teacher/marks-entry/correction-requests/'.$batch.'/approve', []],
            ['POST', '/api/teacher/marks-entry/correction-requests/'.$batch.'/reject', []],
        ];

        foreach ($requests as [$method, $url, $payload]) {
            $this->withToken($this->token)->json($method, $url, $payload)
                ->assertForbidden()
                ->assertJsonPath(
                    'message',
                    'Assessment changes are unavailable while the school is read-only.'
                );
        }

        $this->assertSame($before, $this->assessmentState());
    }

    public function test_read_only_preserves_authorized_report_card_and_pdf_reads(): void
    {
        app(MeritListService::class)->generate(
            $this->fixture['school_id'], $this->fixture['exam_id'],
            null, null, $this->fixture['actor_id']
        );
        app(ReportCardService::class)->generateSummary(
            $this->fixture['school_id'], $this->fixture['exam_id'],
            null, null, null, $this->fixture['actor_id']
        );

        $card = DB::table('report_cards')
            ->where('school_id', $this->fixture['school_id'])
            ->where('exam_id', $this->fixture['exam_id'])->first();

        $this->assertNotNull($card);
        $this->setSchoolState('read_only');
        $before = $this->assessmentState();

        $this->withToken($this->token)
            ->getJson('/api/report-cards/'.$card->id)
            ->assertOk()->assertJsonPath('data.id', $card->id);

        $pdf = $this->withToken($this->token)
            ->get('/api/report-cards/'.$card->id.'/pdf');
        $pdf->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $pdf->getContent());

        $this->assertSame($before, $this->assessmentState());
    }

    public function test_reactivation_allows_generation_with_the_existing_token(): void
    {
        app(MeritListService::class)->generate(
            $this->fixture['school_id'], $this->fixture['exam_id'],
            null, null, $this->fixture['actor_id']
        );

        $payload = [
            'exam_id' => $this->fixture['exam_id'],
            'response_mode' => 'summary',
        ];

        $this->setSchoolState('read_only');
        $this->withToken($this->token)
            ->postJson('/api/report-cards/generate', $payload)
            ->assertForbidden();

        $this->setSchoolState('active');
        $this->withToken($this->token)
            ->postJson('/api/report-cards/generate', $payload)
            ->assertOk()->assertJsonPath('data.generated_count', 1);
    }

    public function test_read_only_does_not_bypass_report_card_permissions(): void
    {
        $this->setSchoolState('read_only');

        $user = User::withoutGlobalScopes()
            ->findOrFail($this->fixture['actor_id']);
        $permission = DB::table('permissions')
            ->where('permission_name', 'generate_report_cards')->value('id');

        DB::table('role_permissions')
            ->where('role_id', $user->role_id)
            ->where('permission_id', $permission)->delete();

        $this->withToken($this->token)
            ->getJson('/api/report-cards')->assertForbidden();
    }

    public function test_unavailable_school_states_reject_assessment_reads_and_writes(): void
    {
        $before = $this->assessmentState();

        foreach (['locked', 'suspended', 'archived'] as $state) {
            // Keep active=true to verify the lifecycle state itself blocks access.
            $this->setSchoolState($state);

            $this->withToken($this->token)
                ->getJson('/api/report-cards')
                ->assertForbidden()
                ->assertJsonPath('message', 'Access is unavailable.');

            $this->withToken($this->token)
                ->postJson('/api/report-cards/generate', [
                    'exam_id' => $this->fixture['exam_id'],
                    'response_mode' => 'summary',
                ])
                ->assertForbidden()
                ->assertJsonPath('message', 'Access is unavailable.');
        }

        $this->assertSame($before, $this->assessmentState());
    }

    private function setSchoolState(string $state): void
    {
        DB::table('schools')->where('id', $this->fixture['school_id'])
            ->update(['lifecycle_state' => $state, 'active' => true]);
    }

    private function assessmentState(): array
    {
        $state = [];
        foreach ([
            'exams', 'exam_learning_areas', 'exam_results',
            'learning_area_results', 'exam_rosters', 'exam_roster_learners',
            'exam_grading_snapshots', 'mark_entry_permissions',
            'merit_lists', 'report_cards',
        ] as $table) {
            $query = DB::table($table);
            if ($table === 'exams') {
                $query->where('id', $this->fixture['exam_id']);
            } else {
                $query->where('exam_id', $this->fixture['exam_id']);
            }
            $state[$table] = $query->orderBy('id')->get()
                ->map(fn ($row) => (array) $row)->all();
        }

        $cardIds = collect($state['report_cards'])->pluck('id');
        $state['details'] = DB::table('report_card_learning_areas')
            ->whereIn('report_card_id', $cardIds)->orderBy('id')->get()
            ->map(fn ($row) => (array) $row)->all();

        return $state;
    }
}
