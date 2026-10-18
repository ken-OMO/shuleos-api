<?php

namespace Tests\Feature;

use App\Models\Learner;
use App\Models\User;
use App\Services\Assessment\ResultProcessingService;
use App\Services\Attendance\AttendanceReadService;
use App\Services\LearnerPortal\LearnerAcademicProgressService;
use App\Services\LearnerPortal\LearnerPortalAccessService;
use App\Services\LearnerPortal\LearnerPortalService;
use App\Services\ParentPortal\ParentLearnerProgressService;
use App\Services\ParentPortal\ParentPortalAccessService;
use App\Services\ParentPortal\ParentPortalMobileService;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mockery;

class ExamAbsencePortalTest extends LearningAreaResultTest
{
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
