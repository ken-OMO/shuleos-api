<?php

namespace Tests\Feature;

use App\Models\Learner;
use App\Models\User;
use App\Services\Assessment\ResultProcessingService;
use App\Services\Attendance\AttendanceReadService;
use App\Services\LearnerPortal\LearnerAcademicProgressService;
use App\Services\LearnerPortal\LearnerAccountService;
use App\Services\LearnerPortal\LearnerPortalAccessService;
use App\Services\LearnerPortal\LearnerPortalService;
use App\Services\ParentPortal\ParentLearnerProgressService;
use App\Services\ParentPortal\ParentPortalAccessService;
use App\Services\ParentPortal\ParentPortalMobileService;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mockery;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;

class ExamAbsencePortalTest extends LearningAreaResultTest
{
    public function test_portal_grades_and_progress_survive_live_scale_edits(): void
    {
        $context = $this->portalContext();
        $user = $context['user'];
        $learnerId = $context['learner']->id;

        $beforeLearner = app(LearnerPortalService::class)->results($user);
        $beforeParent = app(ParentPortalMobileService::class)->results($user, $learnerId);
        $beforeProgress = $this->learnerProgress()->summary($user);

        DB::table('grading_scales')
            ->where('grading_system_id', $context['result']->grading_system_id)
            ->update([
                'grade_code' => 'CHANGED',
                'grade_description' => 'Changed live description',
                'points' => 1,
            ]);

        $afterLearner = app(LearnerPortalService::class)->results($user);
        $afterParent = app(ParentPortalMobileService::class)->results($user, $learnerId);
        $afterProgress = $this->learnerProgress()->summary($user);

        $this->assertEquals($beforeLearner, $afterLearner);
        $this->assertEquals($beforeParent, $afterParent);
        $this->assertEquals(
            $beforeProgress['grade_distribution'], $afterProgress['grade_distribution']
        );
        $this->assertEquals(
            $beforeProgress['learning_areas'], $afterProgress['learning_areas']
        );
        $this->assertSame('EE2', $afterLearner->first()->grade_code);
        $this->assertSame(7, $afterLearner->first()->points);
    }

    public function test_portals_keep_legacy_fallback_and_captured_null_points(): void
    {
        $context = $this->portalContext();
        $user = $context['user'];

        DB::table('exam_results')
            ->where('exam_id', $context['exam']->id)
            ->where('learner_id', $context['learner']->id)
            ->update(['marks' => 25]);

        $areaId = DB::table('exam_learning_areas')
            ->where('exam_id', $context['exam']->id)->value('id');

        app(ResultProcessingService::class)->process(
            $user->school_id, $areaId, $context['learner']->id, $user->id
        );

        $result = DB::table('learning_area_results')
            ->where('id', $context['result']->id)->first();

        DB::table('grading_scales')->where('id', $result->grading_scale_id)
            ->update(['grade_code' => 'LIVE', 'points' => 99]);

        $captured = app(LearnerPortalService::class)->results($user)->first();
        $this->assertSame('ME', $captured->grade_code);
        $this->assertNull($captured->points);

        $parent = app(ParentPortalMobileService::class)->results(
            $user, $context['learner']->id
        );
        $this->assertNull($parent[0]['learning_areas']->first()['points']);

        DB::table('learning_area_results')->where('id', $result->id)
            ->update(['grading_snapshot' => null]);

        $legacy = app(LearnerPortalService::class)->results($user)->first();
        $this->assertSame('LIVE', $legacy->grade_code);
        $this->assertSame(99, (int) $legacy->points);
    }

    public function test_progress_respects_results_visibility_and_restores_scores(): void
    {
        $context = $this->portalContext();
        $user = $context['user'];
        $progress = $this->learnerProgress();

        DB::table('school_settings')->updateOrInsert(
            ['school_id' => $user->school_id],
            ['learner_portal_show_results' => true]
        );

        $before = $progress->summary($user);
        $this->assertSame(1, $before['published_periods']);
        $this->assertSame(75.0, $before['average_percentage']);
        $this->assertCount(1, $before['learning_areas']);

        DB::table('school_settings')->where('school_id', $user->school_id)
            ->update(['learner_portal_show_results' => false]);

        $hidden = $progress->summary($user);
        $this->assertSame(0, $hidden['published_periods']);
        $this->assertNull($hidden['average_percentage']);
        $this->assertCount(0, $hidden['learning_areas']);
        $this->assertCount(0, $hidden['grade_distribution']);
        $this->assertSame($before['homework'], $hidden['homework']);
        $this->assertSame($before['attendance'], $hidden['attendance']);
        $this->assertCount(0, $progress->trends($user)['learning_areas']);

        DB::table('school_settings')->where('school_id', $user->school_id)
            ->update(['learner_portal_show_results' => true]);

        $restored = $progress->summary($user);
        $this->assertSame($before['average_percentage'], $restored['average_percentage']);
        $this->assertEquals($before['learning_areas'], $restored['learning_areas']);
    }

    public function test_deleted_exams_are_excluded_from_learner_progress_and_parent_trends(): void
    {
        $context = $this->portalContext();
        $user = $context['user'];
        $learnerId = $context['learner']->id;

        $widgets = Mockery::mock(ParentPortalMobileService::class);
        $widgets->shouldReceive('attendanceSummary')->andReturn([]);
        $widgets->shouldReceive('homework')->andReturn(
            new LengthAwarePaginator([], 0, 20)
        );
        $widgets->shouldReceive('reportCards')->andReturn(collect());
        $widgets->shouldReceive('results')->andReturn([]);

        $parent = new ParentLearnerProgressService($context['parentAccess'], $widgets);
        $learner = $this->learnerProgress();

        $this->assertSame(1, $learner->summary($user)['published_periods']);
        $this->assertCount(1, $parent->summary($user, $learnerId, 'trends')['trends']);

        DB::table('exams')->where('id', $context['exam']->id)
            ->update(['is_deleted' => true]);

        $hidden = $learner->summary($user);
        $this->assertSame(0, $hidden['published_periods']);
        $this->assertNull($hidden['average_percentage']);
        $this->assertCount(0, $hidden['learning_areas']);
        $this->assertCount(0, $parent->summary($user, $learnerId, 'trends')['trends']);
        $this->assertCount(0, app(LearnerPortalService::class)->results($user));
        $this->assertSame([], app(ParentPortalMobileService::class)->results($user, $learnerId));

        DB::table('exams')->where('id', $context['exam']->id)
            ->update(['is_deleted' => false]);

        $this->assertSame(1, $learner->summary($user)['published_periods']);
        $this->assertCount(1, $parent->summary($user, $learnerId, 'trends')['trends']);
    }

    public function test_authenticated_result_widgets_respect_visibility_and_deleted_exams(): void
    {
        $context = $this->portalContext();
        $schoolId = $context['user']->school_id;

        // Use real learner authorization for every HTTP request.
        $this->app->forgetInstance(LearnerPortalAccessService::class);
        DB::table('school_settings')->updateOrInsert(
            ['school_id' => $schoolId],
            [
                'learner_portal_enabled' => true,
                'learner_portal_show_results' => true,
            ]
        );
        DB::table('learners')->where('id', $context['learner']->id)
            ->update(['portal_enabled' => true]);

        $account = app(LearnerAccountService::class)
            ->create($schoolId, $context['learner']->id, []);
        $user = User::withoutGlobalScopes()->with('role')
            ->findOrFail($account['user']['id']);

        config(['jwt.secret' => str_repeat('assessment-http-test-', 4)]);

        foreach ([
            'access_learner_portal',
            'access_learner_portal_phase_two',
            'view_own_results',
            'view_own_academic_progress',
            'view_own_learner_analytics',
        ] as $name) {
            $permission = DB::table('permissions')
                ->where('permission_name', $name)->value('id');
            $this->assertNotNull($permission, 'Missing permission: '.$name);

            if (! DB::table('role_permissions')
                ->where('role_id', $user->role_id)
                ->where('permission_id', $permission)->exists()) {
                DB::table('role_permissions')->insert([
                    'id' => (string) Str::uuid(),
                    'role_id' => $user->role_id,
                    'permission_id' => $permission,
                    'created_at' => now(),
                ]);
            }
        }

        $token = JWTAuth::fromUser($user);

        foreach (['visible', 'disabled', 'deleted_exam', 'restored'] as $state) {
            $this->assertSame(
                1,
                DB::table('school_settings')->where('school_id', $schoolId)
                    ->update(['learner_portal_show_results' => $state !== 'disabled']),
                'The visibility fixture must update exactly one school setting.'
            );
            DB::table('exams')->where('id', $context['exam']->id)
                ->update(['is_deleted' => $state === 'deleted_exam']);

            $visible = in_array($state, ['visible', 'restored'], true);

            $dashboard = $this->withToken($token)
                ->getJson('/api/learner/dashboard')->assertOk();
            $this->assertArrayHasKey('attendance_summary', $dashboard->json('data'));

            $progress = $this->withToken($token)
                ->getJson('/api/learner/progress')->assertOk();
            $analytics = $this->withToken($token)
                ->getJson('/api/learner/analytics')->assertOk();
            $trends = $this->withToken($token)
                ->getJson('/api/learner/progress/trends')->assertOk();

            if ($visible) {
                $this->assertSame(
                    $context['exam']->id,
                    $dashboard->json('data.latest_result.exam_id')
                );
                $this->assertEquals(
                    75, $dashboard->json('data.latest_result.average_percentage')
                );
                $progress->assertJsonPath('data.published_periods', 1);
                $analytics->assertJsonPath('data.result_periods_available', 1);
                $this->assertCount(1, $analytics->json('data.learning_area_trends'));
                $this->assertCount(1, $trends->json('data.learning_areas'));
            } else {
                $this->assertNull($dashboard->json('data.latest_result'));
                $progress->assertJsonPath('data.published_periods', 0)
                    ->assertJsonPath('data.average_percentage', null);
                $this->assertSame([], $progress->json('data.learning_areas'));
                $analytics->assertJsonPath('data.result_periods_available', 0);
                $this->assertSame([], $analytics->json('data.learning_area_trends'));
                $this->assertSame([], $trends->json('data.learning_areas'));
            }

            $results = $this->withToken($token)->getJson('/api/learner/results');
            if ($state === 'disabled') {
                $results->assertForbidden();
            } else {
                $results->assertOk();
                $this->assertCount($visible ? 1 : 0, $results->json('data'));
            }
        }
    }

    private function portalContext(): array
    {
        $processor = DB::table('users')
            ->where('role_id', DB::table('roles')
                ->where('role_name', 'Results Processor')->value('id'))
            ->first();

        $user = User::withoutGlobalScopes()->findOrFail($processor->id);
        $exam = DB::table('exams')->where('created_by', $user->id)->first();
        $area = DB::table('exam_learning_areas')->where('exam_id', $exam->id)->first();
        $gradeId = DB::table('grades')->where('school_id', $user->school_id)
            ->where('grade_name', 'like', 'Junior Grade %')->value('id');

        $learner = Learner::withoutGlobalScopes()
            ->where('school_id', $user->school_id)
            ->where('grade_id', $gradeId)->firstOrFail();

        app(ResultProcessingService::class)->process(
            $user->school_id, $area->id, $learner->id, $user->id
        );

        DB::table('exams')->where('id', $exam->id)->update(['status' => 'published']);

        $result = DB::table('learning_area_results')
            ->where('exam_id', $exam->id)->where('learner_id', $learner->id)->first();

        $learnerAccess = Mockery::mock(LearnerPortalAccessService::class);
        $learnerAccess->shouldReceive('learner')->with($user)->andReturn($learner);
        $this->app->instance(LearnerPortalAccessService::class, $learnerAccess);

        $parentAccess = Mockery::mock(ParentPortalAccessService::class);
        $parentAccess->shouldReceive('requireLinkedLearner')
            ->with($user, $learner->id)->andReturn($learner);
        $this->app->instance(ParentPortalAccessService::class, $parentAccess);

        return compact('user', 'exam', 'learner', 'result', 'parentAccess');
    }

    private function learnerProgress(): LearnerAcademicProgressService
    {
        $attendance = Mockery::mock(AttendanceReadService::class);
        $attendance->shouldReceive('learner')
            ->andReturn(DB::table('learner_attendance')->whereRaw('1 = 0'));
        $attendance->shouldReceive('summary')->andReturn([]);

        return new LearnerAcademicProgressService(
            app(LearnerPortalAccessService::class),
            $attendance
        );
    }

    private function markPortalAbsent(string $resultId): void
    {
        DB::table('learning_area_results')->where('id', $resultId)->update([
            'is_absent' => true,
            'marks_obtained' => null,
            'percentage' => null,
            'grading_system_id' => null,
            'grading_scale_id' => null,
        ]);
    }

    private function historicalPortalResult(
        array $context,
        float $percentage,
        string $processedAt,
        bool $absent = false
    ): void {
        $exam = (array) DB::table('exams')->where('id', $context['exam']->id)->first();
        $exam['id'] = (string) Str::uuid();
        $exam['exam_name'] = 'Portal history '.Str::random(8);
        DB::table('exams')->insert($exam);

        $row = (array) $context['result'];
        $row['id'] = (string) Str::uuid();
        $row['exam_id'] = $exam['id'];
        $row['processed_at'] = $processedAt;
        $row['is_absent'] = $absent;
        $row['marks_obtained'] = $absent ? null : $percentage;
        $row['percentage'] = $absent ? null : $percentage;
        $row['grading_system_id'] = $absent ? null : $context['result']->grading_system_id;
        $row['grading_scale_id'] = $absent ? null : DB::table('grading_scales')
            ->where('grading_system_id', $row['grading_system_id'])
            ->where('min_score', '<=', $percentage)
            ->where('max_score', '>=', $percentage)->value('id');

        DB::table('learning_area_results')->insert($row);
    }

    public function test_learner_results_keep_absence_visible_and_zero_scored(): void
    {
        $context = $this->portalContext();
        $this->markPortalAbsent($context['result']->id);

        $service = app(LearnerPortalService::class);
        $rows = $service->results($context['user']);

        $this->assertCount(1, $rows);
        $this->assertTrue((bool) $rows->first()->is_absent);
        $this->assertNull($rows->first()->marks_obtained);
        $this->assertNull($rows->first()->percentage);
        $this->assertNull($rows->first()->grade_code);

        $zeroScale = DB::table('grading_scales')
            ->where('grading_system_id', $context['result']->grading_system_id)
            ->where('min_score', '<=', 0)->where('max_score', '>=', 0)->value('id');

        DB::table('learning_area_results')->where('id', $context['result']->id)->update([
            'is_absent' => false,
            'marks_obtained' => 0,
            'percentage' => 0,
            'grading_system_id' => $context['result']->grading_system_id,
            'grading_scale_id' => $zeroScale,
        ]);

        $rows = $service->results($context['user']);
        $this->assertCount(1, $rows);
        $this->assertFalse((bool) $rows->first()->is_absent);
        $this->assertSame(0.0, (float) $rows->first()->marks_obtained);
        $this->assertNotNull($rows->first()->grade_code);
    }

    public function test_all_absent_learner_progress_has_no_zero_average_or_trend(): void
    {
        $context = $this->portalContext();
        $this->markPortalAbsent($context['result']->id);

        $summary = $this->learnerProgress()->summary($context['user']);
        $area = $summary['learning_areas']->first();

        $this->assertNull($summary['average_percentage']);
        $this->assertSame(1, $summary['absent_results']);
        $this->assertNull($area['average_percentage']);
        $this->assertSame(0, $area['scored_periods']);
        $this->assertSame(1, $area['absent_periods']);
        $this->assertNull($area['change_percentage_points']);
        $this->assertSame('insufficient_data', $area['direction']);
        $this->assertCount(0, $summary['grade_distribution']);
    }

    public function test_learner_trend_uses_scored_periods_and_includes_zero(): void
    {
        $context = $this->portalContext();
        DB::table('learning_area_results')->where('id', $context['result']->id)
            ->update(['processed_at' => '2026-07-03 12:00:00']);

        $this->historicalPortalResult($context, 0, '2026-07-01 12:00:00');
        $this->historicalPortalResult($context, 0, '2026-07-02 12:00:00', true);

        $summary = $this->learnerProgress()->summary($context['user']);
        $area = $summary['learning_areas']->first();

        $this->assertSame(37.5, $summary['average_percentage']);
        $this->assertSame(37.5, $area['average_percentage']);
        $this->assertSame(2, $area['scored_periods']);
        $this->assertSame(1, $area['absent_periods']);
        $this->assertSame(75.0, $area['change_percentage_points']);
        $this->assertSame('improving', $area['direction']);
    }

    public function test_parent_results_and_trends_preserve_absence_without_zero_totals(): void
    {
        $context = $this->portalContext();
        $this->markPortalAbsent($context['result']->id);

        $results = app(ParentPortalMobileService::class)
            ->results($context['user'], $context['learner']->id);

        $this->assertCount(1, $results);
        $this->assertTrue($results[0]['has_absence']);
        $this->assertNull($results[0]['total_score']);
        $this->assertNull($results[0]['average_percentage']);
        $this->assertTrue((bool) $results[0]['learning_areas']->first()['is_absent']);

        // Stub unrelated widgets; exercise the real database-backed trend calculation.
        $widgets = Mockery::mock(ParentPortalMobileService::class);
        $widgets->shouldReceive('attendanceSummary')->andReturn([]);
        $widgets->shouldReceive('homework')->andReturn(
            new LengthAwarePaginator([], 0, 20)
        );
        $widgets->shouldReceive('reportCards')->andReturn(collect());
        $widgets->shouldReceive('results')->andReturn($results);

        $progress = new ParentLearnerProgressService($context['parentAccess'], $widgets);
        $trends = $progress->summary(
            $context['user'], $context['learner']->id, 'trends'
        )['trends'];

        $this->assertCount(1, $trends);
        $this->assertNull($trends[0]['first_percentage']);
        $this->assertNull($trends[0]['latest_percentage']);
        $this->assertNull($trends[0]['change']);
        $this->assertSame('insufficient_data', $trends[0]['indicator']);

        $this->historicalPortalResult($context, 0, '2026-07-01 12:00:00');
        $this->historicalPortalResult($context, 75, '2026-07-03 12:00:00');

        $trends = $progress->summary(
            $context['user'], $context['learner']->id, 'trends'
        )['trends'];

        $this->assertSame(0.0, $trends[0]['first_percentage']);
        $this->assertSame(75.0, $trends[0]['latest_percentage']);
        $this->assertSame(75.0, $trends[0]['change']);
        $this->assertSame(2, $trends[0]['scored_periods']);
        $this->assertSame(1, $trends[0]['absent_periods']);
        $this->assertSame('improving', $trends[0]['indicator']);
    }
}
