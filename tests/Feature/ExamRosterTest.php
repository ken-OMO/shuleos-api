<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Assessment\AssessmentTypeService;
use App\Services\Assessment\ExamCompletenessService;
use App\Services\Assessment\ExamLearningAreaService;
use App\Services\Assessment\ExamPaperService;
use App\Services\Assessment\ExamRosterService;
use App\Services\Assessment\ExamService;
use App\Services\Assessment\ResultProcessingService;
use App\Services\Auth\AuthContextService;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\Database\AcademicYearBuilder;
use Tests\Support\Database\EducationLevelBuilder;
use Tests\Support\Database\GradeBuilder;
use Tests\Support\Database\GradingScaleBuilder;
use Tests\Support\Database\GradingSystemBuilder;
use Tests\Support\Database\LearnerBuilder;
use Tests\Support\Database\LearningAreaBuilder;
use Tests\Support\Database\RoleBuilder;
use Tests\Support\Database\SchoolBuilder;
use Tests\Support\Database\StreamBuilder;
use Tests\Support\Database\TermBuilder;
use Tests\Support\Database\UserBuilder;
use Tests\TestCase;

class ExamRosterTest extends TestCase
{
    use DatabaseTransactions;

    private object $school;

    private object $user;

    private object $learner;

    private object $exam;

    private object $area;

    protected function setUp(): void
    {
        parent::setUp();

        if (! Schema::hasColumn('exam_results', 'is_absent')) {
            $migration = require database_path('migrations/2026_10_17_090000_add_exam_absence_fields.php');
            $migration->up();
        }

        if (! Schema::hasTable('exam_rosters')) {
            $migration = require database_path('migrations/2026_10_17_060000_create_exam_roster_tables.php');
            $migration->up();
        }

        $this->school = SchoolBuilder::create();
        $role = RoleBuilder::create(['role_name' => 'Exam Officer '.Str::random(6)]);
        $this->user = UserBuilder::create($this->school, $role);
        $level = EducationLevelBuilder::create([
            'level_name' => 'Primary Roster '.Str::random(8),
            'level_order' => 1,
        ]);
        $grade = GradeBuilder::create($this->school, $level);
        $stream = StreamBuilder::create($this->school, $grade);
        $this->learner = LearnerBuilder::create($this->school, $grade, $stream);
        $year = AcademicYearBuilder::create($this->school);
        $term = TermBuilder::create($this->school, $year);
        $type = app(AssessmentTypeService::class)->create(
            ['assessment_type_name' => 'Formative'], $this->school->id
        );
        $this->exam = app(ExamService::class)->create([
            'exam_name' => 'Roster Test',
            'assessment_type_id' => $type->id,
            'academic_year_id' => $year->id,
            'term_id' => $term->id,
            'start_date' => '2026-07-20',
            'end_date' => '2026-07-25',
        ], $this->school->id, $this->user->id);

        $learningArea = LearningAreaBuilder::create();
        $this->area = app(ExamLearningAreaService::class)->create([
            'exam_id' => $this->exam->id,
            'learning_area_id' => $learningArea->id,
            'number_of_papers' => 2,
            'total_marks' => 100,
        ], $this->school->id);

        foreach ([1, 2] as $number) {
            app(ExamPaperService::class)->create([
                'exam_learning_area_id' => $this->area->id,
                'paper_name' => 'Paper '.$number,
                'paper_number' => $number,
                'max_marks' => 50,
            ], $this->school->id);
        }
    }

    public function test_it_snapshots_selected_papers_and_membership(): void
    {
        $result = $this->save();

        $this->assertSame(1, $result['version']);
        $this->assertSame(1, $result['learner_count']);
        $this->assertDatabaseHas('exam_roster_learners', [
            'roster_id' => $result['id'],
            'learner_id' => $this->learner->id,
            'grade_id' => $this->learner->grade_id,
            'stream_id' => $this->learner->stream_id,
        ]);
        $rows = DB::table('exam_roster_papers')->get();
        $this->assertCount(2, $rows);
        foreach ($rows as $row) {
            $this->assertSame(50.0, (float) $row->maximum_marks);
            $this->assertSame(2, (int) $row->expected_paper_count);
            $this->assertSame(100.0, (float) $row->learning_area_total_marks);
        }
    }

    public function test_replacement_preserves_membership_and_rejects_stale_versions(): void
    {
        $first = $this->save();
        $before = DB::table('exam_roster_learners')->first();

        $grade = GradeBuilder::create($this->school);
        $stream = StreamBuilder::create($this->school, $grade);
        DB::table('learners')->where('id', $this->learner->id)->update([
            'grade_id' => $grade->id, 'stream_id' => $stream->id,
        ]);

        $second = $this->save(1);
        $after = DB::table('exam_roster_learners')->first();
        $this->assertSame($first['id'], $second['id']);
        $this->assertSame(2, $second['version']);
        $this->assertSame($before->id, $after->id);
        $this->assertSame($before->grade_id, $after->grade_id);
        $this->assertSame($before->stream_id, $after->stream_id);

        $snapshot = $this->snapshot();
        $this->reject(fn () => $this->save(1), 'version');
        $this->assertSame($snapshot, $this->snapshot());
    }

    public function test_invalid_mixed_selection_leaves_existing_roster_unchanged(): void
    {
        $this->save();
        $snapshot = $this->snapshot();

        $this->reject(fn () => $this->save(1, [
            $this->selection(),
            [
                'learner_id' => (string) Str::uuid(),
                'exam_learning_area_ids' => [$this->area->id],
            ],
        ]), 'learners');

        $this->assertSame($snapshot, $this->snapshot());
    }

    public function test_cross_school_exam_and_actor_are_rejected(): void
    {
        $other = SchoolBuilder::create();
        $service = app(ExamRosterService::class);

        $this->reject(fn () => $service->replace(
            $other->id, $this->exam->id, $this->user->id, 0, [$this->selection()]
        ), 'exam_id');

        $role = RoleBuilder::create(['role_name' => 'Other Officer '.Str::random(6)]);
        $otherUser = UserBuilder::create($other, $role);
        $this->reject(fn () => $service->replace(
            $this->school->id, $this->exam->id, $otherUser->id, 0, [$this->selection()]
        ), 'user_id');

        $this->assertSame(0, DB::table('exam_rosters')->count());
    }

    public function test_missing_paper_configuration_is_rejected_without_writes(): void
    {
        DB::table('exam_papers')->where('exam_learning_area_id', $this->area->id)
            ->where('paper_number', 2)->update(['is_deleted' => true]);

        $this->reject(fn () => $this->save(), 'papers');
        $this->assertSame(0, DB::table('exam_rosters')->count());
    }

    public function test_published_exam_roster_cannot_be_replaced(): void
    {
        $this->save();
        app(ExamService::class)->transition($this->exam, 'published');
        $snapshot = $this->snapshot();

        $this->reject(fn () => $this->save(1), 'exam_id');
        $this->assertSame($snapshot, $this->snapshot());
    }

    public function test_completeness_reports_an_unconfigured_roster(): void
    {
        $report = $this->completeness();

        $this->assertFalse($report['roster_configured']);
        $this->assertFalse($report['complete']);
        $this->assertSame(['roster_not_configured'], $report['issues']);
        $this->assertSame(0, DB::table('exam_rosters')->count());
    }

    public function test_completeness_identifies_missing_papers_without_writing(): void
    {
        $this->save();
        $before = $this->snapshot();

        $report = $this->completeness();
        $area = $report['learners'][0]['learning_areas'][0];

        $this->assertFalse($report['complete']);
        $this->assertSame(1, $report['learner_count']);
        $this->assertCount(2, $area['missing_paper_ids']);
        $this->assertContains('missing_papers', $area['issues']);
        $this->assertContains('unprocessed', $area['issues']);
        $this->assertSame($before, $this->snapshot());
        $this->assertSame(0, DB::table('exam_results')
            ->where('exam_id', $this->exam->id)->count());
    }

    public function test_zero_marks_are_entered_results_and_can_be_complete(): void
    {
        $this->save();
        $this->insertCompletenessMarks(0);

        $area = $this->completeness()['learners'][0]['learning_areas'][0];
        $this->assertSame([], $area['missing_paper_ids']);
        $this->assertSame([], $area['invalid_paper_ids']);
        $this->assertSame(['unprocessed'], $area['issues']);

        $this->processCompletenessMarks();
        $report = $this->completeness();

        $this->assertTrue($report['complete']);
        $this->assertSame(1, $report['complete_learner_count']);
        $this->assertSame([], $report['learners'][0]['learning_areas'][0]['issues']);

        DB::table('learning_area_results')->where('exam_id', $this->exam->id)
            ->update(['processing_status' => 'stale']);

        $report = $this->completeness();
        $this->assertFalse($report['complete']);
        $this->assertContains(
            'stale_or_unprocessed',
            $report['learners'][0]['learning_areas'][0]['issues']
        );
    }

    public function test_completeness_detects_changed_configuration_and_processed_values(): void
    {
        $this->save();
        $this->insertCompletenessMarks(20);
        $this->processCompletenessMarks();
        $this->assertTrue($this->completeness()['complete']);

        DB::table('learning_area_results')->where('exam_id', $this->exam->id)
            ->update(['marks_obtained' => 41]);

        $report = $this->completeness();
        $this->assertFalse($report['complete']);
        $this->assertContains(
            'processed_values_mismatch',
            $report['learners'][0]['learning_areas'][0]['issues']
        );

        DB::table('exam_papers')->where('exam_learning_area_id', $this->area->id)
            ->where('paper_number', 1)->update(['max_marks' => 60]);

        $this->assertContains(
            'configuration_changed',
            $this->completeness()['learners'][0]['learning_areas'][0]['issues']
        );
        $this->assertSame(50.0, (float) DB::table('exam_roster_papers')
            ->where('paper_number', 1)->value('maximum_marks'));
    }

    public function test_results_for_an_unrostered_learner_are_reported_as_unexpected(): void
    {
        $this->save();
        $this->insertCompletenessMarks(20);
        $this->processCompletenessMarks();

        $grade = DB::table('grades')->where('id', $this->learner->grade_id)->first();
        $stream = DB::table('streams')->where('id', $this->learner->stream_id)->first();
        $other = LearnerBuilder::create($this->school, $grade, $stream);
        $this->insertCompletenessMarks(10, $other->id);

        $report = $this->completeness();
        $this->assertFalse($report['complete']);
        $this->assertSame(2, $report['unexpected_paper_result_count']);
        $this->assertSame(1, $report['complete_learner_count']);
        $this->assertCount(1, $report['learners']);
    }

    public function test_completeness_rejects_another_school_exam(): void
    {
        $other = SchoolBuilder::create();
        $this->reject(fn () => app(
            ExamCompletenessService::class
        )->report($other->id, $this->exam->id), 'exam_id');
    }

    public function test_processed_absence_is_complete_and_numeric_absence_is_detected(): void
    {
        $this->save();
        $this->insertCompletenessMarks(20);

        $paperId = DB::table('exam_papers')
            ->where('exam_learning_area_id', $this->area->id)
            ->orderBy('paper_number')->value('id');

        DB::table('exam_results')
            ->where('learner_id', $this->learner->id)
            ->where('paper_id', $paperId)
            ->update(['is_absent' => true, 'marks' => null]);

        $beforeProcessing = $this->completeness();
        $area = $beforeProcessing['learners'][0]['learning_areas'][0];

        $this->assertFalse($beforeProcessing['complete']);
        $this->assertSame([], $area['missing_paper_ids']);
        $this->assertSame([], $area['invalid_paper_ids']);
        $this->assertSame([$paperId], $area['absent_paper_ids']);
        $this->assertContains('unprocessed', $area['issues']);

        $this->processCompletenessMarks();

        $report = $this->completeness();
        $area = $report['learners'][0]['learning_areas'][0];

        $this->assertTrue($report['complete']);
        $this->assertSame(1, $area['absent_paper_count']);
        $this->assertSame([], $area['issues']);

        DB::table('learning_area_results')
            ->where('exam_id', $this->exam->id)
            ->where('learner_id', $this->learner->id)
            ->update(['marks_obtained' => 0, 'percentage' => 0]);

        $report = $this->completeness();
        $this->assertFalse($report['complete']);
        $this->assertContains(
            'processed_values_mismatch',
            $report['learners'][0]['learning_areas'][0]['issues']
        );
    }

    public function test_completeness_keeps_missing_paper_unresolved_alongside_absence(): void
    {
        $this->save();
        $this->insertCompletenessMarks(20);

        $papers = DB::table('exam_papers')
            ->where('exam_learning_area_id', $this->area->id)
            ->orderBy('paper_number')->pluck('id');

        DB::table('exam_results')
            ->where('learner_id', $this->learner->id)
            ->where('paper_id', $papers[0])
            ->update(['is_absent' => true, 'marks' => null]);

        DB::table('exam_results')
            ->where('learner_id', $this->learner->id)
            ->where('paper_id', $papers[1])
            ->delete();

        $report = $this->completeness();
        $area = $report['learners'][0]['learning_areas'][0];

        $this->assertFalse($report['complete']);
        $this->assertSame([$papers[0]], $area['absent_paper_ids']);
        $this->assertSame([$papers[1]], $area['missing_paper_ids']);
        $this->assertSame([], $area['invalid_paper_ids']);
        $this->assertContains('missing_papers', $area['issues']);
        $this->assertContains('unprocessed', $area['issues']);
    }

    private function completeness(): array
    {
        return app(ExamCompletenessService::class)
            ->report($this->school->id, $this->exam->id);
    }

    private function insertCompletenessMarks(float $marks, ?string $learnerId = null): void
    {
        $papers = DB::table('exam_papers')
            ->where('exam_learning_area_id', $this->area->id)->get();

        foreach ($papers as $paper) {
            DB::table('exam_results')->insert([
                'id' => (string) Str::uuid(),
                'exam_id' => $this->exam->id,
                'learner_id' => $learnerId ?? $this->learner->id,
                'learning_area_id' => $this->area->learning_area_id,
                'paper_id' => $paper->id,
                'marks' => $marks,
                'entered_by' => $this->user->id,
                'is_deleted' => false,
                'created_at' => now(),
            ]);
        }
    }

    private function processCompletenessMarks(): void
    {
        $levelId = DB::table('grades')->where('id', $this->learner->grade_id)
            ->value('education_level_id');
        $level = DB::table('education_levels')->where('id', $levelId)->first();

        $system = GradingSystemBuilder::create(
            $this->school, $level, ['grading_name' => 'Roster Scale '.Str::random(8)]
        );
        GradingScaleBuilder::create($system, [
            'grade_code' => 'TEST',
            'grade_description' => 'Test scale',
            'min_score' => 0,
            'max_score' => 100,
            'points' => null,
            'sort_order' => 1,
        ]);

        app(ResultProcessingService::class)->process(
            $this->school->id, $this->area->id,
            $this->learner->id, $this->user->id
        );
    }

    public function test_http_roster_round_trip_and_completeness(): void
    {
        $this->rosterHttpContext(true);
        $url = '/api/exams/'.$this->exam->id;

        $this->getJson($url.'/roster')
            ->assertOk()
            ->assertJsonPath('data.roster_configured', false)
            ->assertJsonPath('data.version', 0);

        $this->putJson($url.'/roster', [
            'version' => 0,
            'learners' => [$this->selection()],
            'school_id' => (string) Str::uuid(),
        ])->assertOk()->assertJsonPath('data.version', 1);

        $this->getJson($url.'/roster')
            ->assertOk()
            ->assertJsonPath('data.roster_configured', true)
            ->assertJsonPath('data.learners.0.learner_id', $this->learner->id)
            ->assertJsonCount(2, 'data.learners.0.papers');

        $this->getJson($url.'/completeness')
            ->assertOk()
            ->assertJsonPath('data.complete', false)
            ->assertJsonPath('data.learner_count', 1)
            ->assertJsonCount(2, 'data.learners.0.learning_areas.0.missing_paper_ids');

        $before = $this->snapshot();
        $this->putJson($url.'/roster', [
            'version' => 0, 'learners' => [$this->selection()],
        ])->assertUnprocessable()->assertJsonValidationErrors('version');
        $this->assertSame($before, $this->snapshot());
    }

    public function test_http_permission_denial_blocks_every_roster_endpoint(): void
    {
        $this->rosterHttpContext(false);
        $url = '/api/exams/'.$this->exam->id;

        $this->getJson($url.'/roster')->assertForbidden();
        $this->getJson($url.'/completeness')->assertForbidden();
        $this->putJson($url.'/roster', [
            'version' => 0, 'learners' => [$this->selection()],
        ])->assertForbidden();

        $this->assertSame(0, DB::table('exam_rosters')->count());
    }

    public function test_http_uses_tenant_context_when_exam_belongs_to_another_school(): void
    {
        $other = SchoolBuilder::create();
        $role = RoleBuilder::create(['role_name' => 'Foreign Officer '.Str::random(6)]);
        $otherUser = UserBuilder::create($other, $role);
        $this->rosterHttpContext(true, $otherUser->id);
        $url = '/api/exams/'.$this->exam->id;

        $this->getJson($url.'/roster')
            ->assertUnprocessable()->assertJsonValidationErrors('exam_id');
        $this->getJson($url.'/completeness')
            ->assertUnprocessable()->assertJsonValidationErrors('exam_id');
        $this->putJson($url.'/roster', [
            'school_id' => $this->school->id,
            'version' => 0,
            'learners' => [$this->selection()],
        ])->assertUnprocessable()->assertJsonValidationErrors('exam_id');

        $this->assertSame(0, DB::table('exam_rosters')->count());
    }

    private function rosterHttpContext(bool $allowed, ?string $userId = null): void
    {
        $user = User::withoutGlobalScopes()
            ->findOrFail($userId ?? $this->user->id);
        $this->actingAs($user);

        $access = \Mockery::mock(AuthContextService::class);
        $access->shouldReceive('hasPermission')
            ->withArgs(fn ($actor, $permission) => $actor->id === $user->id && $permission === 'create_exam'
            )->andReturn($allowed);
        $this->app->instance(AuthContextService::class, $access);

        // Retain the real module permission middleware; isolate JWT/tenant setup.
        $this->app->make(Kernel::class);
        $aliases = $this->app['router']->getMiddleware();
        foreach (['jwt', 'tenant'] as $alias) {
            $this->app->instance($aliases[$alias], new ExamRosterHttpContextMiddleware);
        }
    }

    public function test_completeness_detects_an_additional_unsnapshotted_paper(): void
    {
        $this->save();
        $this->insertCompletenessMarks(20);
        $this->processCompletenessMarks();
        $this->assertTrue($this->completeness()['complete']);

        $paper = (array) DB::table('exam_papers')
            ->where('exam_learning_area_id', $this->area->id)->first();
        $paper['id'] = (string) Str::uuid();
        $paper['paper_number'] = 3;
        $paper['paper_name'] = 'Unexpected Paper 3';
        DB::table('exam_papers')->insert($paper);

        $report = $this->completeness();
        $this->assertFalse($report['complete']);
        $this->assertContains(
            'configuration_changed',
            $report['learners'][0]['learning_areas'][0]['issues']
        );
        $this->assertSame(2, DB::table('exam_roster_papers')->count());
    }

    public function test_real_jwt_can_save_and_read_the_tenant_exam_roster(): void
    {
        $token = $this->realRosterLogin(true);
        $url = '/api/exams/'.$this->exam->id;

        $this->withToken($token)->putJson($url.'/roster', [
            'school_id' => (string) Str::uuid(),
            'version' => 0,
            'learners' => [$this->selection()],
        ])->assertOk()->assertJsonPath('data.version', 1);

        $this->withToken($token)->getJson($url.'/roster')
            ->assertOk()
            ->assertJsonPath('data.learners.0.learner_id', $this->learner->id);

        $this->withToken($token)->getJson($url.'/completeness')
            ->assertOk()
            ->assertJsonPath('data.roster_configured', true)
            ->assertJsonPath('data.complete', false);

        $this->assertDatabaseHas('exam_rosters', [
            'exam_id' => $this->exam->id,
            'school_id' => $this->school->id,
        ]);
    }

    public function test_real_jwt_without_exam_permission_cannot_access_roster_endpoints(): void
    {
        $token = $this->realRosterLogin(false);
        $url = '/api/exams/'.$this->exam->id;

        $this->withToken($token)->getJson($url.'/roster')->assertForbidden();
        $this->withToken($token)->getJson($url.'/completeness')->assertForbidden();
        $this->withToken($token)->putJson($url.'/roster', [
            'version' => 0, 'learners' => [$this->selection()],
        ])->assertForbidden();

        $this->assertSame(0, DB::table('exam_rosters')->count());
    }

    public function test_real_middleware_rejects_unauthenticated_roster_requests(): void
    {
        $url = '/api/exams/'.$this->exam->id;

        $this->getJson($url.'/roster')->assertUnauthorized();
        $this->getJson($url.'/completeness')->assertUnauthorized();
        $this->putJson($url.'/roster', [
            'version' => 0, 'learners' => [$this->selection()],
        ])->assertUnauthorized();

        $this->assertSame(0, DB::table('exam_rosters')->count());
    }

    private function realRosterLogin(bool $grant): string
    {
        config([
            'jwt.secret' => str_repeat('exam-roster-integration-secret-', 3),
            'jwt.ttl' => 60,
        ]);

        DB::table('schools')->where('id', $this->school->id)->update([
            'active' => true, 'is_deleted' => false, 'lifecycle_state' => 'active',
        ]);
        DB::table('users')->where('id', $this->user->id)->update([
            'password_hash' => Hash::make('roster-test-password'),
            'active' => true,
            'is_deleted' => false,
            'first_login' => false,
            'auth_generation' => 1,
        ]);

        $record = DB::table('users')->where('id', $this->user->id)->first();
        DB::table('roles')->where('id', $record->role_id)->update(['active' => true]);

        if ($grant) {
            $permissionId = DB::table('permissions')
                ->where('permission_name', 'create_exam')->value('id');
            if (! $permissionId) {
                $permissionId = (string) Str::uuid();
                DB::table('permissions')->insert([
                    'id' => $permissionId,
                    'permission_name' => 'create_exam',
                    'created_at' => now(),
                ]);
            }

            DB::table('role_permissions')->insertOrIgnore([
                'id' => (string) Str::uuid(),
                'role_id' => $record->role_id,
                'permission_id' => $permissionId,
                'created_at' => now(),
            ]);
        }

        $response = $this->postJson('/api/auth/login', [
            'username' => $record->username,
            'password' => 'roster-test-password',
        ])->assertOk();

        $token = $response->json('token') ?? $response->json('data.token');
        $this->assertIsString($token);
        $this->assertNotSame('', $token);

        return $token;
    }

    private function selection(): array
    {
        return [
            'learner_id' => $this->learner->id,
            'exam_learning_area_ids' => [$this->area->id],
        ];
    }

    private function save(int $version = 0, ?array $selections = null): array
    {
        return app(ExamRosterService::class)->replace(
            $this->school->id, $this->exam->id, $this->user->id,
            $version, $selections ?? [$this->selection()]
        );
    }

    private function reject(callable $operation, string $key): void
    {
        try {
            $operation();
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($key, $exception->errors());

            return;
        }

        $this->fail('Expected roster validation rejection.');
    }

    private function snapshot(): array
    {
        $snapshot = [];
        foreach (['exam_rosters', 'exam_roster_learners', 'exam_roster_papers'] as $table) {
            $snapshot[$table] = DB::table($table)->orderBy('id')->get()
                ->map(fn ($row) => (array) $row)->all();
        }

        return $snapshot;
    }
}

class ExamRosterHttpContextMiddleware
{
    public function handle(
        Request $request,
        \Closure $next
    ): Response {
        $request->attributes->set('tenant_school_id', auth()->user()->school_id);

        return $next($request);
    }
}
