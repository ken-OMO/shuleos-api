<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Assessment\ReportCardService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ReportCardTest extends TestCase
{
    private array $id = [];

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['report_card_learning_areas', 'report_cards', 'pathway_recommendations', 'learning_area_results', 'merit_lists', 'grading_scales', 'grading_systems', 'learning_areas', 'learners', 'streams', 'grades', 'education_levels', 'exams', 'terms', 'academic_years', 'users', 'schools'] as $t) {
            Schema::dropIfExists($t);
        }
        Schema::create('schools', fn (Blueprint $t) => $t->uuid('id')->primary());
        Schema::create('users', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('school_id');
            $t->uuid('role_id')->nullable();
        });
        Schema::create('academic_years', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('school_id');
        });
        Schema::create('terms', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('school_id');
        });
        Schema::create('education_levels', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('school_id');
            $t->string('level_name');
        });
        Schema::create('grades', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('school_id');
            $t->uuid('education_level_id');
        });
        Schema::create('streams', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('school_id');
            $t->uuid('grade_id');
        });
        Schema::create('learners', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('school_id');
            $t->uuid('grade_id');
            $t->uuid('stream_id');
            $t->boolean('active');
            $t->boolean('is_deleted');
            $t->timestamps();
        });
        Schema::create('exams', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('school_id');
            $t->uuid('academic_year_id');
            $t->uuid('term_id');
            $t->date('start_date')->nullable();
            $t->date('end_date')->nullable();
            $t->boolean('is_deleted');
        });
        Schema::create('learning_areas', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('learning_area_name');
        });
        Schema::create('grading_systems', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('school_id');
            $t->uuid('education_level_id');
            $t->string('grading_name');
            $t->boolean('uses_points');
            $t->boolean('uses_marks');
            $t->boolean('active');
            $t->timestamp('created_at')->nullable();
        });
        Schema::create('grading_scales', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('grading_system_id');
            $t->string('grade_code');
            $t->string('grade_description');
            $t->decimal('min_score', 5, 2);
            $t->decimal('max_score', 5, 2);
            $t->integer('points')->nullable();
            $t->integer('sort_order')->nullable();
            $t->timestamp('created_at')->nullable();
        });
        Schema::create('merit_lists', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('school_id');
            $t->uuid('exam_id');
            $t->uuid('learner_id');
            $t->uuid('grade_id');
            $t->uuid('stream_id');
            $t->decimal('total_score', 10, 2);
            $t->decimal('maximum_marks', 10, 2);
            $t->decimal('average_percentage', 6, 2);
            $t->integer('total_points')->nullable();
            $t->uuid('overall_grading_system_id');
            $t->uuid('overall_grading_scale_id');
            $t->integer('stream_position');
            $t->integer('grade_position');
            $t->integer('school_position');
            $t->string('status');
            $t->boolean('is_deleted');
            $t->timestamps();
        });
        Schema::create('learning_area_results', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('school_id');
            $t->uuid('exam_id');
            $t->uuid('learner_id');
            $t->uuid('learning_area_id');
            $t->decimal('marks_obtained', 8, 2);
            $t->decimal('maximum_marks', 8, 2);
            $t->decimal('percentage', 6, 2);
            $t->uuid('grading_system_id');
            $t->uuid('grading_scale_id');
            $t->string('processing_status');
            $t->boolean('is_deleted');
        });
        Schema::create('pathway_recommendations', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('learner_id');
            $t->uuid('academic_year_id');
            $t->date('recommendation_date')->nullable();
            $t->string('recommended_pathway');
            $t->decimal('confidence_score', 5, 2)->nullable();
            $t->text('strengths')->nullable();
            $t->text('improvement_areas')->nullable();
            $t->string('generated_by')->nullable();
            $t->timestamp('created_at')->nullable();
        });
        Schema::create('report_cards', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('school_id');
            $t->uuid('learner_id');
            $t->uuid('exam_id');
            $t->uuid('academic_year_id');
            $t->uuid('term_id');
            $t->uuid('merit_list_id');
            $t->uuid('grade_id');
            $t->uuid('stream_id');
            $t->decimal('overall_score', 10, 2);
            $t->decimal('maximum_marks', 10, 2);
            $t->decimal('average_percentage', 6, 2);
            $t->string('overall_grade')->nullable();
            $t->uuid('overall_grading_system_id');
            $t->uuid('overall_grading_scale_id');
            $t->integer('total_points')->nullable();
            $t->integer('stream_position');
            $t->integer('grade_position');
            $t->integer('school_position');
            $t->integer('total_learners');
            foreach (['attendance_present', 'attendance_absent', 'attendance_late', 'attendance_total_sessions'] as $f) {
                $t->integer($f)->nullable();
            }$t->decimal('attendance_percentage', 5, 2)->nullable();
            $t->text('class_teacher_comment')->nullable();
            $t->text('principal_comment')->nullable();
            $t->text('pathway_recommendation')->nullable();
            $t->uuid('pathway_recommendation_id')->nullable();
            $t->string('status');
            $t->uuid('generated_by');
            $t->timestamp('generated_at');
            $t->uuid('published_by')->nullable();
            $t->timestamp('published_at')->nullable();
            $t->timestamps();
            $t->boolean('is_deleted');
            $t->timestamp('deleted_at')->nullable();
            $t->uuid('deleted_by')->nullable();
            $t->unique(['school_id', 'exam_id', 'learner_id']);
        });
        Schema::create('report_card_learning_areas', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('report_card_id');
            $t->uuid('learning_area_id');
            $t->uuid('learning_area_result_id');
            $t->decimal('score', 8, 2);
            $t->decimal('marks_obtained', 8, 2);
            $t->decimal('maximum_marks', 8, 2);
            $t->decimal('percentage', 6, 2);
            $t->uuid('grading_system_id');
            $t->uuid('grading_scale_id');
            $t->string('grade_code');
            $t->integer('points')->nullable();
            $t->text('teacher_comment')->nullable();
            $t->timestamps();
            $t->boolean('is_deleted');
            $t->timestamp('deleted_at')->nullable();
            $t->uuid('deleted_by')->nullable();
            $t->unique(['report_card_id', 'learning_area_id']);
        });
        foreach (['school', 'other', 'user', 'year', 'term', 'junior_level', 'primary_level', 'junior_grade', 'primary_grade', 'junior_stream', 'primary_stream', 'junior', 'primary', 'exam', 'exam_other', 'system_j', 'system_p', 'scale_j', 'scale_p', 'area1', 'area2', 'merit_j', 'merit_p', 'pathway'] as $k) {
            $this->id[$k] = (string) Str::uuid();
        } DB::table('schools')->insert([['id' => $this->id['school']], ['id' => $this->id['other']]]);
        DB::table('users')->insert(['id' => $this->id['user'], 'school_id' => $this->id['school']]);
        DB::table('academic_years')->insert(['id' => $this->id['year'], 'school_id' => $this->id['school']]);
        DB::table('terms')->insert(['id' => $this->id['term'], 'school_id' => $this->id['school']]);
        DB::table('education_levels')->insert([['id' => $this->id['junior_level'], 'school_id' => $this->id['school'], 'level_name' => 'Junior School'], ['id' => $this->id['primary_level'], 'school_id' => $this->id['school'], 'level_name' => 'Primary']]);
        DB::table('grades')->insert([['id' => $this->id['junior_grade'], 'school_id' => $this->id['school'], 'education_level_id' => $this->id['junior_level']], ['id' => $this->id['primary_grade'], 'school_id' => $this->id['school'], 'education_level_id' => $this->id['primary_level']]]);
        DB::table('streams')->insert([['id' => $this->id['junior_stream'], 'school_id' => $this->id['school'], 'grade_id' => $this->id['junior_grade']], ['id' => $this->id['primary_stream'], 'school_id' => $this->id['school'], 'grade_id' => $this->id['primary_grade']]]);
        DB::table('learners')->insert([['id' => $this->id['junior'], 'school_id' => $this->id['school'], 'grade_id' => $this->id['junior_grade'], 'stream_id' => $this->id['junior_stream'], 'active' => 1, 'is_deleted' => 0], ['id' => $this->id['primary'], 'school_id' => $this->id['school'], 'grade_id' => $this->id['primary_grade'], 'stream_id' => $this->id['primary_stream'], 'active' => 1, 'is_deleted' => 0]]);
        DB::table('exams')->insert([['id' => $this->id['exam'], 'school_id' => $this->id['school'], 'academic_year_id' => $this->id['year'], 'term_id' => $this->id['term'], 'start_date' => '2026-01-01', 'end_date' => '2026-04-01', 'is_deleted' => 0], ['id' => $this->id['exam_other'], 'school_id' => $this->id['other'], 'academic_year_id' => $this->id['year'], 'term_id' => $this->id['term'], 'start_date' => null, 'end_date' => null, 'is_deleted' => 0]]);
        DB::table('learning_areas')->insert([['id' => $this->id['area1'], 'learning_area_name' => 'Math'], ['id' => $this->id['area2'], 'learning_area_name' => 'English']]);
        DB::table('grading_systems')->insert([['id' => $this->id['system_j'], 'school_id' => $this->id['school'], 'education_level_id' => $this->id['junior_level'], 'grading_name' => 'Junior', 'uses_points' => 1, 'uses_marks' => 1, 'active' => 1], ['id' => $this->id['system_p'], 'school_id' => $this->id['school'], 'education_level_id' => $this->id['primary_level'], 'grading_name' => 'Primary', 'uses_points' => 0, 'uses_marks' => 1, 'active' => 1]]);
        DB::table('grading_scales')->insert([['id' => $this->id['scale_j'], 'grading_system_id' => $this->id['system_j'], 'grade_code' => 'EE', 'grade_description' => 'Exceeding', 'min_score' => 0, 'max_score' => 100, 'points' => 8], ['id' => $this->id['scale_p'], 'grading_system_id' => $this->id['system_p'], 'grade_code' => 'ME', 'grade_description' => 'Meeting', 'min_score' => 0, 'max_score' => 100, 'points' => null]]);
        $this->seedLearner('junior', 'merit_j', 'system_j', 'scale_j', 16);
        $this->seedLearner('primary', 'merit_p', 'system_p', 'scale_p', null);
        DB::table('pathway_recommendations')->insert(['id' => $this->id['pathway'], 'learner_id' => $this->id['junior'], 'academic_year_id' => $this->id['year'], 'recommendation_date' => '2026-03-01', 'recommended_pathway' => 'STEM', 'confidence_score' => 90]);
    }

    private function seedLearner(string $l, string $m, string $s, string $scale, ?int $points): void
    {
        DB::table('merit_lists')->insert(['id' => $this->id[$m], 'school_id' => $this->id['school'], 'exam_id' => $this->id['exam'], 'learner_id' => $this->id[$l], 'grade_id' => $this->id[$l.'_grade'], 'stream_id' => $this->id[$l.'_stream'], 'total_score' => 160, 'maximum_marks' => 200, 'average_percentage' => 80, 'total_points' => $points, 'overall_grading_system_id' => $this->id[$s], 'overall_grading_scale_id' => $this->id[$scale], 'stream_position' => 1, 'grade_position' => 1, 'school_position' => 1, 'status' => 'generated', 'is_deleted' => 0]);
        foreach (['area1', 'area2'] as $a) {
            DB::table('learning_area_results')->insert(['id' => (string) Str::uuid(), 'school_id' => $this->id['school'], 'exam_id' => $this->id['exam'], 'learner_id' => $this->id[$l], 'learning_area_id' => $this->id[$a], 'marks_obtained' => 80, 'maximum_marks' => 100, 'percentage' => 80, 'grading_system_id' => $this->id[$s], 'grading_scale_id' => $this->id[$scale], 'processing_status' => 'processed', 'is_deleted' => 0]);
        }
    }

    private function generate(?string $l = null, ?string $g = null, ?string $s = null)
    {
        return app(ReportCardService::class)->generate($this->id['school'], $this->id['exam'], $l, $g, $s, $this->id['user']);
    }

    public function test_generates_card_copies_overall_data_and_details(): void
    {
        $c = $this->generate($this->id['junior'])->first();
        $this->assertSame('160.00', $c->overall_score);
        $this->assertSame(16, $c->total_points);
        $this->assertSame('EE', $c->overallGradingScale->grade_code);
        $this->assertCount(2, $c->learningAreas);
        $this->assertSame(8, $c->learningAreas->first()->points);
        $this->assertNull($c->attendance_percentage);
    }

    public function test_filters_and_junior_only_pathway(): void
    {
        $this->assertCount(1, $this->generate(null, $this->id['junior_grade']));
        DB::table('report_cards')->delete();
        DB::table('report_card_learning_areas')->delete();
        $junior = $this->generate(null, null, $this->id['junior_stream'])->first();
        $this->assertSame('STEM', $junior->pathway_recommendation);
        DB::table('report_cards')->delete();
        DB::table('report_card_learning_areas')->delete();
        $primary = $this->generate($this->id['primary'])->first();
        $this->assertNull($primary->pathway_recommendation_id);
    }

    public function test_missing_merit_and_results_are_rejected(): void
    {
        DB::table('merit_lists')->delete();
        $this->expectException(ValidationException::class);
        $this->generate();
    }

    public function test_missing_learning_areas_is_rejected(): void
    {
        DB::table('learning_area_results')->where('learner_id', $this->id['junior'])->delete();
        $this->expectException(ValidationException::class);
        $this->generate();
    }

    public function test_cross_school_is_rejected(): void
    {
        $this->expectException(ValidationException::class);
        app(ReportCardService::class)->generate($this->id['school'], $this->id['exam_other'], null, null, null, $this->id['user']);
    }

    public function test_upsert_preserves_and_updates_comments_only(): void
    {
        $c = $this->generate($this->id['junior'])->first();
        $d = $c->learningAreas->first();
        app(ReportCardService::class)->updateComments($this->id['school'], $c->id, ['class_teacher_comment' => 'Good', 'principal_comment' => 'Approved', 'learning_areas' => [['id' => $d->id, 'teacher_comment' => 'Strong']]]);
        $again = $this->generate($this->id['junior'])->first();
        $this->assertSame($c->id, $again->id);
        $this->assertSame('Good', $again->class_teacher_comment);
        $this->assertSame('Strong', $again->learningAreas->firstWhere('id', $d->id)->teacher_comment);
        $this->assertDatabaseCount('report_cards', 1);
        $this->assertDatabaseCount('report_card_learning_areas', 2);
    }

    public function test_publishes_generated_cards(): void
    {
        $this->generate($this->id['junior']);
        $rows = app(ReportCardService::class)->publish($this->id['school'], $this->id['exam'], $this->id['junior'], null, null, $this->id['user']);
        $this->assertSame('published', $rows->first()->status);
        $this->assertNotNull($rows->first()->published_at);
    }

    public function test_api_output_contains_required_sections(): void
    {
        $this->withoutMiddleware();
        $u = new User;
        $u->forceFill(['id' => $this->id['user'], 'school_id' => $this->id['school']]);
        Auth::setUser($u);
        $this->postJson('/api/report-cards/generate', ['school_id' => $this->id['school'], 'exam_id' => $this->id['exam'], 'learner_id' => $this->id['junior']])->assertOk()->assertJsonPath('data.0.overall_grade', 'EE')->assertJsonPath('data.0.total_points', 16)->assertJsonPath('data.0.stream_position', 1)->assertJsonPath('data.0.attendance.percentage', null)->assertJsonPath('data.0.pathway_recommendation', 'STEM')->assertJsonCount(2, 'data.0.learning_areas');
    }
}
