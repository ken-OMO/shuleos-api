<?php

namespace Tests\Feature;

use App\Models\Learner;
use App\Models\User;
use App\Services\Assessment\AssessmentRegistrationService;
use App\Services\Assessment\AssessmentTypeService;
use App\Services\Assessment\ExamCompletenessService;
use App\Services\Assessment\ExamGradingSnapshotService;
use App\Services\Assessment\ExamLearningAreaService;
use App\Services\Assessment\ExamPaperService;
use App\Services\Assessment\ExamRosterService;
use App\Services\Assessment\ExamService;
use App\Services\Assessment\MarkEntryPermissionService;
use App\Services\Assessment\MeritListService;
use App\Services\Assessment\ReportCardService;
use App\Services\Assessment\ResultProcessingService;
use App\Services\TeacherPortal\MarkEntryBatchService;
use App\Services\TeacherPortal\MarkModerationService;
use Illuminate\Auth\Access\AuthorizationException;
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

    public function test_direct_processing_and_output_writes_reject_unavailable_school_states(): void
    {
        $school = $this->fixture['school_id'];
        $exam = $this->fixture['exam_id'];
        $actor = $this->fixture['actor_id'];

        $operations = [
            fn () => app(ResultProcessingService::class)
                ->process($school, $this->fixture['exam_learning_area_ids'][0],
                    $this->fixture['learner_ids'][0], $actor),
            fn () => app(ExamRosterService::class)
                ->replace($school, $exam, $actor, 1, [[
                    'learner_id' => $this->fixture['learner_ids'][0],
                    'exam_learning_area_ids' => $this->fixture['exam_learning_area_ids'],
                ]]),
            fn () => app(MeritListService::class)
                ->generate($school, $exam, null, null, $actor),
            fn () => app(ReportCardService::class)
                ->generateSummary($school, $exam, null, null, null, $actor),
        ];

        $before = $this->assessmentState();
        $transactionLevel = DB::transactionLevel();

        foreach (['read_only', 'locked', 'suspended', 'archived'] as $state) {
            $this->setSchoolState($state);

            foreach ($operations as $operation) {
                try {
                    $operation();
                    $this->fail('Direct assessment writes must reject '.$state);
                } catch (AuthorizationException $exception) {
                    $this->assertSame(
                        'Assessment changes are unavailable for this school.',
                        $exception->getMessage()
                    );
                }

                $this->assertSame($transactionLevel, DB::transactionLevel());
                $this->assertSame($before, $this->assessmentState());
            }
        }
    }

    public function test_read_only_preserves_direct_completeness_reports(): void
    {
        $this->setSchoolState('read_only');
        $before = $this->assessmentState();

        app(ExamCompletenessService::class)->report(
            $this->fixture['school_id'], $this->fixture['exam_id']
        );

        $this->assertSame($before, $this->assessmentState());
    }

    public function test_direct_creation_rejects_unavailable_schools_before_validation(): void
    {
        $school = $this->fixture['school_id'];
        $actor = $this->fixture['actor_id'];
        $operations = [
            fn () => app(AssessmentTypeService::class)
                ->create([], $school),
            fn () => app(AssessmentRegistrationService::class)
                ->create([], $school, $actor),
            fn () => app(ExamService::class)
                ->create([], $school, $actor),
            fn () => app(ExamLearningAreaService::class)
                ->create([], $school),
            fn () => app(ExamPaperService::class)
                ->create([], $school),
            fn () => app(MarkEntryPermissionService::class)
                ->create([], $school),
        ];
        $level = DB::transactionLevel();
        $before = $this->assessmentState();

        foreach (['read_only', 'locked', 'suspended', 'archived'] as $state) {
            $this->setSchoolState($state);
            foreach ($operations as $operation) {
                try {
                    $operation();
                    $this->fail('Creation must reject '.$state);
                } catch (AuthorizationException $exception) {
                    $this->assertSame(
                        'Assessment changes are unavailable for this school.',
                        $exception->getMessage()
                    );
                }
                $this->assertSame($level, DB::transactionLevel());
                $this->assertSame($before, $this->assessmentState());
            }
        }
    }

    public function test_active_school_can_create_an_assessment_type(): void
    {
        $this->setSchoolState('active');
        $type = app(AssessmentTypeService::class)
            ->create(['assessment_type_name' => 'Lifecycle '.Str::uuid()],
                $this->fixture['school_id']);

        $this->assertSame($this->fixture['school_id'], $type->school_id);
        $this->assertDatabaseHas('assessment_types', ['id' => $type->id]);
    }

    public function test_restricted_schools_cannot_delete_types_or_start_moderation_writes(): void
    {
        $user = User::withoutGlobalScopes()
            ->findOrFail($this->fixture['actor_id']);
        $type = app(AssessmentTypeService::class)
            ->create(['assessment_type_name' => 'Protected '.Str::uuid()],
                $this->fixture['school_id']);
        $before = $type->fresh()->getAttributes();
        $missingId = (string) Str::uuid();
        $level = DB::transactionLevel();

        // Invalid IDs ensure restrictions are checked before record lookup.
        // Valid teacher/batch workflows require separate integration coverage.
        $operations = [
            fn () => app(AssessmentTypeService::class)
                ->delete($type, $user->id),
            fn () => app(MarkEntryBatchService::class)
                ->correction($user, $missingId, ['reason' => 'Correction']),
            fn () => app(MarkModerationService::class)
                ->decide($user, $missingId, 'approved', null),
            fn () => app(MarkModerationService::class)
                ->decideCorrection($user, $missingId, 'approved', null),
            fn () => app(MarkModerationService::class)
                ->decideCorrection($user, $missingId, 'rejected', 'Rejected'),
        ];

        foreach (['read_only', 'locked', 'suspended', 'archived'] as $state) {
            $this->setSchoolState($state);
            foreach ($operations as $operation) {
                try {
                    $operation();
                    $this->fail('Restricted school write must be denied.');
                } catch (AuthorizationException $exception) {
                    $this->assertSame(
                        'Assessment changes are unavailable for this school.',
                        $exception->getMessage()
                    );
                }
                $this->assertSame($level, DB::transactionLevel());
                $this->assertSame($before, $type->fresh()->getAttributes());
            }
        }
    }

    public function test_read_only_can_reuse_grading_rules_but_cannot_capture_new_rules(): void
    {
        $school = $this->fixture['school_id'];
        $exam = $this->fixture['exam_id'];
        $actor = $this->fixture['actor_id'];
        $learner = Learner::withoutGlobalScopes()
            ->findOrFail($this->fixture['learner_ids'][0]);
        $service = app(ExamGradingSnapshotService::class);

        $calculate = fn () => DB::transaction(
            fn () => $service->calculate($school, $exam, $learner, 80.0, $actor)
        );
        $award = $calculate();
        $this->setSchoolState('read_only');
        $this->assertSame($award, $calculate());

        DB::table('exam_grading_snapshots')
            ->where('school_id', $school)->where('exam_id', $exam)->delete();

        try {
            $calculate();
            $this->fail('Read-only schools cannot capture grading rules.');
        } catch (AuthorizationException $exception) {
            $this->assertSame(
                'Assessment changes are unavailable for this school.',
                $exception->getMessage()
            );
        }

        $this->assertSame(0, DB::table('exam_grading_snapshots')
            ->where('school_id', $school)->where('exam_id', $exam)->count());
    }

    public function test_controller_update_holds_school_lock_through_the_write(): void
    {
        $type = app(AssessmentTypeService::class)
            ->create(['assessment_type_name' => 'Controller '.Str::uuid()],
                $this->fixture['school_id']);
        $name = 'Updated '.Str::uuid();
        $connection = DB::connection();
        $wasLogging = $connection->logging();

        try {
            $connection->enableQueryLog();
            $connection->flushQueryLog();

            $this->withToken($this->token)
                ->putJson('/api/assessment-types/'.$type->id, [
                    'assessment_type_name' => $name,
                ])->assertOk();

            $queries = $connection->getQueryLog();
        } finally {
            $connection->flushQueryLog();
            if (! $wasLogging) {
                $connection->disableQueryLog();
            }
        }

        $lockIndex = null;
        $writeIndex = null;
        foreach ($queries as $index => $query) {
            if (str_contains($query['query'], 'from "schools"')
                && str_contains($query['query'], 'for share')) {
                $lockIndex ??= $index;
            }
            if (str_starts_with($query['query'], 'update "assessment_types"')) {
                $writeIndex = $index;
            }
        }

        $this->assertNotNull($lockIndex);
        $this->assertNotNull($writeIndex);
        $this->assertLessThan($writeIndex, $lockIndex);
        $this->assertDatabaseHas('assessment_types', [
            'id' => $type->id, 'assessment_type_name' => $name,
        ]);
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
