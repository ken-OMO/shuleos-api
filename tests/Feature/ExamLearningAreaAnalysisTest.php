<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Assessment\ExamLearningAreaAnalysisService;
use App\Services\Assessment\ResultProcessingService;
use App\Services\LeadershipPortal\LeadershipPortalPhaseTwoService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Tests\Support\Database\GradeBuilder;
use Tests\Support\Database\LearnerBuilder;
use Tests\Support\Database\LearningAreaBuilder;
use Tests\Support\Database\StreamBuilder;

class ExamLearningAreaAnalysisTest extends LearningAreaResultTest
{
    private User $analyst;

    private string $analysisExam;

    private object $analysisLearner;

    private function initialiseAnalysis(): void
    {
        config(['jwt.secret' => str_repeat('exam-analysis-test-secret-', 3)]);

        $processor = DB::table('users')
            ->where('role_id', DB::table('roles')
                ->where('role_name', 'Results Processor')->value('id'))
            ->first();
        $roleId = DB::table('roles')->where('role_name', 'Principal')->value('id');
        if (! $roleId) {
            $roleId = (string) Str::uuid();
            DB::table('roles')->insert(['id' => $roleId, 'role_name' => 'Principal']);
        }
        DB::table('users')->where('id', $processor->id)->update(['role_id' => $roleId]);

        foreach (['access_school_leadership_portal', 'view_academic_insights'] as $name) {
            $permissionId = DB::table('permissions')->where('permission_name', $name)->value('id');
            if (! $permissionId) {
                throw new \RuntimeException("Missing migrated permission [$name].");
            }
            if (! DB::table('role_permissions')->where('role_id', $roleId)
                ->where('permission_id', $permissionId)->exists()) {
                DB::table('role_permissions')->insert([
                    'id' => (string) Str::uuid(),
                    'role_id' => $roleId,
                    'permission_id' => $permissionId,
                ]);
            }
        }

        $this->analyst = User::with('role')->findOrFail($processor->id);
        $this->analysisExam = DB::table('exams')
            ->where('created_by', $processor->id)->value('id');
        $area = DB::table('exam_learning_areas')
            ->where('exam_id', $this->analysisExam)->first();
        $learnerIds = DB::table('exam_results')->where('exam_id', $this->analysisExam)
            ->pluck('learner_id')->unique();

        foreach ($learnerIds as $id) {
            app(ResultProcessingService::class)->process(
                $processor->school_id, $area->id, $id, $processor->id
            );
        }

        $this->analysisLearner = DB::table('learners')
            ->whereIn('id', $learnerIds)
            ->where('grade_id', DB::table('grades')
                ->where('grade_name', 'like', 'Junior Grade %')->value('id'))
            ->first();
    }

    private function becomeHod(string $areaId): void
    {
        $roleId = DB::table('roles')->where('role_name', 'HOD')->value('id');
        if (! $roleId) {
            $roleId = (string) Str::uuid();
            DB::table('roles')->insert(['id' => $roleId, 'role_name' => 'HOD']);
        }

        foreach (['access_school_leadership_portal', 'view_academic_insights'] as $name) {
            $permissionId = DB::table('permissions')->where('permission_name', $name)->value('id');
            if (! DB::table('role_permissions')->where('role_id', $roleId)
                ->where('permission_id', $permissionId)->exists()) {
                DB::table('role_permissions')->insert([
                    'id' => (string) Str::uuid(),
                    'role_id' => $roleId,
                    'permission_id' => $permissionId,
                ]);
            }
        }

        DB::table('users')->where('id', $this->analyst->id)->update(['role_id' => $roleId]);
        $teacherId = (string) Str::uuid();
        DB::table('teachers')->insert([
            'id' => $teacherId,
            'school_id' => $this->analyst->school_id,
            'user_id' => $this->analyst->id,
            'active' => true,
            'is_deleted' => false,
        ]);
        DB::table('hod_assignments')->insert([
            'id' => (string) Str::uuid(),
            'school_id' => $this->analyst->school_id,
            'teacher_id' => $teacherId,
            'learning_area_id' => $areaId,
            'academic_year_id' => DB::table('exams')
                ->where('id', $this->analysisExam)->value('academic_year_id'),
            'active' => true,
        ]);
        $this->analyst = User::with('role')->findOrFail($this->analyst->id);
    }

    public function test_hod_analysis_excludes_unassigned_learning_areas(): void
    {
        $this->initialiseAnalysis();
        $row = (array) DB::table('learning_area_results')
            ->where('exam_id', $this->analysisExam)->first();
        $assignedArea = $row['learning_area_id'];
        $otherArea = LearningAreaBuilder::create([
            'learning_area_name' => 'Unassigned Analysis '.Str::random(8),
        ]);
        $row['id'] = (string) Str::uuid();
        $row['learning_area_id'] = $otherArea->id;
        DB::table('learning_area_results')->insert($row);

        $this->becomeHod($assignedArea);
        $token = JWTAuth::fromUser($this->analyst);
        $response = $this->withToken($token)->getJson($this->endpoint())->assertOk();
        $this->assertSame(
            [$assignedArea],
            array_column($response->json('data.learning_areas'), 'learning_area_id')
        );

        $this->withToken($token)
            ->getJson($this->endpoint().'?learning_area_id='.$otherArea->id)
            ->assertForbidden();
    }

    public function test_hod_without_active_assignment_is_denied(): void
    {
        $this->initialiseAnalysis();
        $areaId = DB::table('learning_area_results')
            ->where('exam_id', $this->analysisExam)->value('learning_area_id');
        $this->becomeHod($areaId);
        DB::table('hod_assignments')->where('school_id', $this->analyst->school_id)
            ->update(['active' => false]);

        $this->withToken(JWTAuth::fromUser($this->analyst))
            ->getJson($this->endpoint())->assertForbidden();
    }

    public function test_cross_school_grade_and_stream_filters_are_unavailable(): void
    {
        $this->initialiseAnalysis();
        $otherSchool = DB::table('schools')
            ->where('id', '!=', $this->analyst->school_id)->first();
        $level = DB::table('education_levels')->first();
        $grade = GradeBuilder::create(
            $otherSchool, $level, ['grade_name' => 'Other Analysis '.Str::random(8)]
        );
        $stream = StreamBuilder::create($otherSchool, $grade);
        $token = JWTAuth::fromUser($this->analyst);

        $this->withToken($token)->getJson($this->endpoint().'?grade_id='.$grade->id)
            ->assertNotFound();
        $this->withToken($token)->getJson($this->endpoint().'?stream_id='.$stream->id)
            ->assertNotFound();
    }

    public function test_stream_must_belong_to_selected_grade(): void
    {
        $this->initialiseAnalysis();
        $otherGradeId = DB::table('grades')->where('school_id', $this->analyst->school_id)
            ->where('id', '!=', $this->analysisLearner->grade_id)->value('id');

        $this->withToken(JWTAuth::fromUser($this->analyst))
            ->getJson($this->endpoint().'?grade_id='.$otherGradeId
                .'&stream_id='.$this->analysisLearner->stream_id)
            ->assertUnprocessable()->assertJsonValidationErrors('stream_id');
    }

    public function test_analysis_excludes_results_from_another_exam(): void
    {
        $this->initialiseAnalysis();
        $exam = (array) DB::table('exams')->where('id', $this->analysisExam)->first();
        $exam['id'] = (string) Str::uuid();
        $exam['exam_name'] = 'Separate Analysis Exam';
        DB::table('exams')->insert($exam);

        $row = (array) DB::table('learning_area_results')
            ->where('exam_id', $this->analysisExam)
            ->where('learner_id', $this->analysisLearner->id)->first();
        $row['id'] = (string) Str::uuid();
        $row['exam_id'] = $exam['id'];
        $row['marks_obtained'] = 100;
        $row['percentage'] = 100;
        DB::table('learning_area_results')->insert($row);

        $data = $this->analysis(['grade_id' => $this->analysisLearner->grade_id]);
        $this->assertSame(1, $data['assessed_learners']);
        $this->assertSame(
            75.0,
            $data['learning_areas'][0]['grading_summaries'][0]['mean_percentage']
        );
    }

    public function test_analysis_excludes_absence_but_keeps_zero_in_means_and_rankings(): void
    {
        $this->initialiseAnalysis();
        $high = $this->addLearner(95);
        $zero = $this->addLearner(0);

        DB::table('learning_area_results')
            ->where('exam_id', $this->analysisExam)
            ->where('learner_id', $this->analysisLearner->id)
            ->update([
                'is_absent' => true,
                'marks_obtained' => null,
                'percentage' => null,
                'grading_system_id' => null,
                'grading_scale_id' => null,
            ]);

        $data = $this->analysis([
            'grade_id' => $this->analysisLearner->grade_id,
        ]);
        $area = $data['learning_areas'][0];
        $summary = $area['grading_summaries'][0];

        $this->assertSame(2, $data['assessed_learners']);
        $this->assertSame(1, $data['absent_learners']);
        $this->assertSame(2, $area['assessed_learners']);
        $this->assertSame(1, $area['absent_learners']);
        $this->assertSame(2, $area['streams'][0]['assessed_learners']);
        $this->assertSame(1, $area['streams'][0]['absent_learners']);
        $this->assertSame(47.5, $summary['mean_percentage']);
        $this->assertSame(47.5, $summary['mark_summaries'][0]['mean_score']);
        $this->assertSame(0.0, $summary['lowest_percentage']);
        $this->assertSame(95.0, $summary['highest_percentage']);
        $this->assertSame(
            2,
            collect($summary['grade_distribution'])->sum('learner_count')
        );
        $this->assertSame(
            [$high->id, $zero->id],
            array_column($summary['top_learners'], 'learner_id')
        );
        $this->assertSame([1, 2], array_column($summary['top_learners'], 'rank'));
    }

    public function test_all_absent_analysis_keeps_counts_without_fabricating_statistics(): void
    {
        $this->initialiseAnalysis();

        DB::table('learning_area_results')
            ->where('exam_id', $this->analysisExam)
            ->update([
                'is_absent' => true,
                'marks_obtained' => null,
                'percentage' => null,
                'grading_system_id' => null,
                'grading_scale_id' => null,
            ]);

        $data = $this->analysis();

        $this->assertSame(0, $data['assessed_learners']);
        $this->assertSame(2, $data['absent_learners']);
        $this->assertCount(1, $data['learning_areas']);

        $area = $data['learning_areas'][0];
        $this->assertSame(0, $area['assessed_learners']);
        $this->assertSame(2, $area['absent_learners']);
        $this->assertSame([], $area['grading_summaries']);
        $this->assertCount(2, $area['streams']);

        foreach ($area['streams'] as $stream) {
            $this->assertSame(0, $stream['assessed_learners']);
            $this->assertSame(1, $stream['absent_learners']);
            $this->assertSame([], $stream['grading_summaries']);
        }

        $this->withToken(JWTAuth::fromUser($this->analyst))
            ->getJson($this->endpoint())
            ->assertOk()
            ->assertJsonPath('data.assessed_learners', 0)
            ->assertJsonPath('data.absent_learners', 2)
            ->assertJsonPath('data.learning_areas.0.grading_summaries', []);
    }

    public function test_analysis_rejects_absence_with_scores_or_grading_references(): void
    {
        $this->initialiseAnalysis();

        $row = DB::table('learning_area_results')
            ->where('exam_id', $this->analysisExam)
            ->where('learner_id', $this->analysisLearner->id)
            ->first();

        $absence = [
            'is_absent' => true,
            'marks_obtained' => null,
            'percentage' => null,
            'grading_system_id' => null,
            'grading_scale_id' => null,
        ];

        $invalidValues = [
            'marks_obtained' => 0,
            'percentage' => 0,
            'grading_system_id' => $row->grading_system_id,
            'grading_scale_id' => $row->grading_scale_id,
            'maximum_marks' => 0,
        ];

        foreach ($invalidValues as $field => $value) {
            DB::table('learning_area_results')
                ->where('id', $row->id)
                ->update(array_replace(
                    $absence,
                    ['maximum_marks' => $row->maximum_marks],
                    [$field => $value]
                ));

            $this->withToken(JWTAuth::fromUser($this->analyst))
                ->getJson($this->endpoint())
                ->assertUnprocessable()
                ->assertJsonValidationErrors('analysis');
        }
    }

    public function test_leadership_summary_counts_absence_without_a_zero_mean(): void
    {
        $this->initialiseAnalysis();

        DB::table('learning_area_results')
            ->where('exam_id', $this->analysisExam)
            ->update([
                'is_absent' => true,
                'marks_obtained' => null,
                'percentage' => null,
                'grading_system_id' => null,
                'grading_scale_id' => null,
            ]);

        $service = app(LeadershipPortalPhaseTwoService::class);
        $summary = $service->academic($this->analyst, 'summary');

        $this->assertSame(2, $summary['processed_results']);
        $this->assertSame(0, $summary['assessed_results']);
        $this->assertSame(2, $summary['absent_results']);
        $this->assertNull($summary['average_percentage']);
        $this->assertNull($summary['minimum_percentage']);
        $this->assertNull($summary['maximum_percentage']);

        $groups = $service->academic($this->analyst, 'learning-areas')['items'];
        $this->assertCount(1, $groups);
        $this->assertSame(0, (int) $groups->first()->assessed_results);
        $this->assertSame(2, (int) $groups->first()->absent_results);
        $this->assertNull($groups->first()->average_percentage);
    }

    private function analysis(array $filters = []): array
    {
        return app(ExamLearningAreaAnalysisService::class)
            ->analyse($this->analyst, $this->analysisExam, $filters);
    }

    private function endpoint(): string
    {
        return '/api/leadership/academics/exams/'.$this->analysisExam.'/learning-area-analysis';
    }

    private function addLearner(float $percentage, ?object $stream = null): object
    {
        $school = DB::table('schools')->find($this->analyst->school_id);
        $grade = DB::table('grades')->find($this->analysisLearner->grade_id);
        $stream ??= DB::table('streams')->find($this->analysisLearner->stream_id);
        $learner = LearnerBuilder::create($school, $grade, $stream);

        $row = (array) DB::table('learning_area_results')
            ->where('exam_id', $this->analysisExam)
            ->where('learner_id', $this->analysisLearner->id)->first();
        $row['id'] = (string) Str::uuid();
        $row['learner_id'] = $learner->id;
        $row['marks_obtained'] = $percentage;
        $row['percentage'] = $percentage;
        $row['grading_scale_id'] = DB::table('grading_scales')
            ->where('grading_system_id', $row['grading_system_id'])
            ->where('min_score', '<=', $percentage)
            ->where('max_score', '>=', $percentage)->value('id');
        DB::table('learning_area_results')->insert($row);

        return $learner;
    }

    public function test_analysis_separates_grading_systems_and_reports_means(): void
    {
        $this->initialiseAnalysis();
        $this->addLearner(95);
        $data = $this->analysis();
        $this->assertSame(3, $data['assessed_learners']);
        $summaries = collect($data['learning_areas'][0]['grading_summaries']);
        $junior = $summaries->firstWhere('assessed_learners', 2);
        $this->assertCount(2, $summaries);
        $this->assertSame(85.0, $junior['mean_percentage']);
        $this->assertSame(85.0, $junior['mark_summaries'][0]['mean_score']);
        $this->assertSame('EE2', $junior['mean_grade']);
        $this->assertSame(2, collect($junior['grade_distribution'])->sum('learner_count'));
    }

    public function test_combined_grade_and_individual_stream_have_distinct_means(): void
    {
        $this->initialiseAnalysis();
        $school = DB::table('schools')->find($this->analyst->school_id);
        $grade = DB::table('grades')->find($this->analysisLearner->grade_id);
        $stream = StreamBuilder::create($school, $grade);
        $this->addLearner(95, $stream);

        $combined = $this->analysis(['grade_id' => $grade->id]);
        $individual = $this->analysis(['stream_id' => $stream->id]);

        $this->assertSame(2, $combined['assessed_learners']);
        $this->assertCount(2, $combined['learning_areas'][0]['streams']);
        $this->assertSame(85.0, $combined['learning_areas'][0]['grading_summaries'][0]['mean_percentage']);
        $this->assertSame(1, $individual['assessed_learners']);
        $this->assertSame(95.0, $individual['learning_areas'][0]['grading_summaries'][0]['mean_percentage']);
    }

    public function test_top_learners_use_competition_ties_and_bounded_output(): void
    {
        $this->initialiseAnalysis();
        $this->addLearner(95);
        $this->addLearner(95);
        $data = $this->analysis([
            'grade_id' => $this->analysisLearner->grade_id,
            'top_limit' => 3,
        ]);
        $top = $data['learning_areas'][0]['grading_summaries'][0]['top_learners'];
        $this->assertSame([1, 1, 3], array_column($top, 'rank'));
        $limited = $this->analysis([
            'grade_id' => $this->analysisLearner->grade_id,
            'top_limit' => 1,
        ]);
        $this->assertCount(1, $limited['learning_areas'][0]['grading_summaries'][0]['top_learners']);
        $this->assertSame(
            ['learner_id', 'rank', 'marks_obtained', 'maximum_marks', 'percentage'],
            array_keys($top[0])
        );
    }

    public function test_deleted_results_are_excluded_and_empty_selection_is_safe(): void
    {
        $this->initialiseAnalysis();
        DB::table('learning_area_results')->where('exam_id', $this->analysisExam)
            ->update(['is_deleted' => true]);
        $data = $this->analysis();
        $this->assertSame(0, $data['assessed_learners']);
        $this->assertSame([], $data['learning_areas']);
    }

    public function test_raw_mean_scores_do_not_mix_different_maximum_marks(): void
    {
        $this->initialiseAnalysis();
        $learner = $this->addLearner(80);
        DB::table('learning_area_results')->where('exam_id', $this->analysisExam)
            ->where('learner_id', $learner->id)
            ->update(['maximum_marks' => 50, 'marks_obtained' => 40]);

        $data = $this->analysis(['grade_id' => $this->analysisLearner->grade_id]);
        $summary = $data['learning_areas'][0]['grading_summaries'][0];
        $this->assertSame(77.5, $summary['mean_percentage']);
        $this->assertCount(2, $summary['mark_summaries']);
    }

    public function test_http_requires_authentication_and_academic_permission(): void
    {
        $this->initialiseAnalysis();
        $this->getJson($this->endpoint())->assertUnauthorized();
        $permission = DB::table('permissions')
            ->where('permission_name', 'view_academic_insights')->value('id');
        DB::table('role_permissions')->where('role_id', $this->analyst->role_id)
            ->where('permission_id', $permission)->delete();

        $this->withToken(JWTAuth::fromUser($this->analyst))
            ->getJson($this->endpoint())->assertForbidden();
    }

    public function test_http_returns_analysis_and_validates_limit(): void
    {
        $this->initialiseAnalysis();
        $token = JWTAuth::fromUser($this->analyst);
        $this->withToken($token)->getJson($this->endpoint())
            ->assertOk()->assertJsonPath('data.assessed_learners', 2);
        $this->withToken($token)->getJson($this->endpoint().'?top_limit=51')
            ->assertUnprocessable()->assertJsonValidationErrors('top_limit');
    }

    public function test_cross_school_exam_is_unavailable(): void
    {
        $this->initialiseAnalysis();
        DB::table('exams')->where('id', $this->analysisExam)
            ->update(['school_id' => DB::table('schools')
                ->where('id', '!=', $this->analyst->school_id)->value('id')]);
        $this->withToken(JWTAuth::fromUser($this->analyst))
            ->getJson($this->endpoint())->assertNotFound();
    }

    public function test_mean_with_overlapping_scales_is_rejected(): void
    {
        $this->initialiseAnalysis();
        $result = DB::table('learning_area_results')
            ->where('learner_id', $this->analysisLearner->id)->first();
        DB::table('grading_scales')->where('grading_system_id', $result->grading_system_id)
            ->update(['min_score' => 0, 'max_score' => 100]);
        $this->withToken(JWTAuth::fromUser($this->analyst))
            ->getJson($this->endpoint())->assertUnprocessable()
            ->assertJsonValidationErrors('grading_scale');
    }

    public function test_executive_response_omits_learner_lists_recursively(): void
    {
        $this->initialiseAnalysis();
        $roleId = DB::table('roles')->where('role_name', 'Director')->value('id');
        if (! $roleId) {
            $roleId = (string) Str::uuid();
            DB::table('roles')->insert(['id' => $roleId, 'role_name' => 'Director']);
        }
        foreach (['access_school_leadership_portal', 'view_academic_insights'] as $name) {
            $permissionId = DB::table('permissions')->where('permission_name', $name)->value('id');
            if (! DB::table('role_permissions')->where('role_id', $roleId)
                ->where('permission_id', $permissionId)->exists()) {
                DB::table('role_permissions')->insert([
                    'id' => (string) Str::uuid(), 'role_id' => $roleId,
                    'permission_id' => $permissionId,
                ]);
            }
        }
        DB::table('users')->where('id', $this->analyst->id)->update(['role_id' => $roleId]);
        $this->analyst = User::with('role')->findOrFail($this->analyst->id);
        $data = $this->analysis();
        $json = json_encode($data);
        $this->assertStringNotContainsString('"top_learners"', $json);
        $this->assertStringNotContainsString('"learner_id"', $json);
        $this->assertSame(2, $data['assessed_learners']);
    }
}
