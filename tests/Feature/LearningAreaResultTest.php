<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Assessment\ResultProcessingService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class LearningAreaResultTest extends TestCase
{
    private array $id;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['learning_area_results', 'grading_scales', 'grading_systems', 'exam_results', 'exam_papers', 'exam_learning_areas', 'learning_areas', 'exams', 'learners', 'grades', 'education_levels', 'users', 'schools'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::create('schools', fn (Blueprint $t) => $t->uuid('id')->primary());
        Schema::create('users', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('school_id');
            $t->uuid('role_id')->nullable();
        });
        Schema::create('education_levels', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('school_id');
            $t->string('level_name')->nullable();
        });
        Schema::create('grades', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('school_id');
            $t->uuid('education_level_id');
        });
        Schema::create('learners', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('school_id');
            $t->uuid('grade_id');
            $t->boolean('active');
            $t->boolean('is_deleted');
            $t->timestamps();
        });
        Schema::create('exams', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('school_id');
            $t->boolean('is_deleted');
        });
        Schema::create('learning_areas', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('learning_area_name')->nullable();
        });
        Schema::create('exam_learning_areas', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('exam_id');
            $t->uuid('learning_area_id');
            $t->integer('number_of_papers');
            $t->decimal('total_marks', 8, 2);
            $t->boolean('is_deleted');
        });
        Schema::create('exam_papers', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('exam_learning_area_id');
            $t->integer('paper_number');
            $t->decimal('max_marks', 8, 2);
            $t->boolean('is_deleted');
        });
        Schema::create('exam_results', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('exam_id');
            $t->uuid('learner_id');
            $t->uuid('learning_area_id');
            $t->uuid('paper_id');
            $t->decimal('marks', 8, 2);
            $t->boolean('is_deleted');
        });
        Schema::create('grading_systems', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('school_id');
            $t->string('grading_name');
            $t->uuid('education_level_id');
            $t->boolean('uses_points')->nullable();
            $t->boolean('uses_marks');
            $t->boolean('active');
            $t->timestamp('created_at')->nullable();
        });
        Schema::create('grading_scales', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('grading_system_id');
            $t->string('grade_code');
            $t->string('grade_description')->nullable();
            $t->decimal('min_score', 5, 2)->nullable();
            $t->decimal('max_score', 5, 2)->nullable();
            $t->integer('points')->nullable();
            $t->integer('sort_order')->nullable();
            $t->timestamp('created_at')->nullable();
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
            $t->uuid('processed_by')->nullable();
            $t->timestamp('processed_at');
            $t->timestamps();
            $t->boolean('is_deleted');
            $t->timestamp('deleted_at')->nullable();
            $t->uuid('deleted_by')->nullable();
            $t->unique(['school_id', 'exam_id', 'learner_id', 'learning_area_id']);
        });
        foreach (['school', 'other_school', 'user', 'junior_level', 'primary_level', 'junior_grade', 'primary_grade', 'junior', 'primary', 'exam', 'area', 'exam_area', 'paper_1', 'paper_2', 'junior_system', 'junior_scale', 'primary_system', 'primary_scale'] as $key) {
            $this->id[$key] = (string) Str::uuid();
        }
        DB::table('schools')->insert([['id' => $this->id['school']], ['id' => $this->id['other_school']]]);
        DB::table('users')->insert(['id' => $this->id['user'], 'school_id' => $this->id['school']]);
        DB::table('education_levels')->insert([['id' => $this->id['junior_level'], 'school_id' => $this->id['school']], ['id' => $this->id['primary_level'], 'school_id' => $this->id['school']]]);
        DB::table('grades')->insert([['id' => $this->id['junior_grade'], 'school_id' => $this->id['school'], 'education_level_id' => $this->id['junior_level']], ['id' => $this->id['primary_grade'], 'school_id' => $this->id['school'], 'education_level_id' => $this->id['primary_level']]]);
        DB::table('learners')->insert([['id' => $this->id['junior'], 'school_id' => $this->id['school'], 'grade_id' => $this->id['junior_grade'], 'active' => true, 'is_deleted' => false], ['id' => $this->id['primary'], 'school_id' => $this->id['school'], 'grade_id' => $this->id['primary_grade'], 'active' => true, 'is_deleted' => false]]);
        DB::table('exams')->insert(['id' => $this->id['exam'], 'school_id' => $this->id['school'], 'is_deleted' => false]);
        DB::table('learning_areas')->insert(['id' => $this->id['area'], 'learning_area_name' => 'Mathematics']);
        DB::table('exam_learning_areas')->insert(['id' => $this->id['exam_area'], 'exam_id' => $this->id['exam'], 'learning_area_id' => $this->id['area'], 'number_of_papers' => 2, 'total_marks' => 100, 'is_deleted' => false]);
        DB::table('exam_papers')->insert([['id' => $this->id['paper_1'], 'exam_learning_area_id' => $this->id['exam_area'], 'paper_number' => 1, 'max_marks' => 50, 'is_deleted' => false], ['id' => $this->id['paper_2'], 'exam_learning_area_id' => $this->id['exam_area'], 'paper_number' => 2, 'max_marks' => 50, 'is_deleted' => false]]);
        DB::table('grading_systems')->insert([['id' => $this->id['junior_system'], 'school_id' => $this->id['school'], 'grading_name' => 'Junior', 'education_level_id' => $this->id['junior_level'], 'uses_points' => true, 'uses_marks' => true, 'active' => true], ['id' => $this->id['primary_system'], 'school_id' => $this->id['school'], 'grading_name' => 'Primary', 'education_level_id' => $this->id['primary_level'], 'uses_points' => false, 'uses_marks' => true, 'active' => true]]);
        DB::table('grading_scales')->insert([['id' => $this->id['junior_scale'], 'grading_system_id' => $this->id['junior_system'], 'grade_code' => 'EE2', 'grade_description' => 'Exceeding Expectation', 'min_score' => 75, 'max_score' => 100, 'points' => 7], ['id' => (string) Str::uuid(), 'grading_system_id' => $this->id['junior_system'], 'grade_code' => 'ME', 'grade_description' => null, 'min_score' => 0, 'max_score' => 74.99, 'points' => null], ['id' => $this->id['primary_scale'], 'grading_system_id' => $this->id['primary_system'], 'grade_code' => 'EE', 'grade_description' => 'Exceeding Expectation', 'min_score' => 75, 'max_score' => 100, 'points' => null], ['id' => (string) Str::uuid(), 'grading_system_id' => $this->id['primary_system'], 'grade_code' => 'ME', 'grade_description' => null, 'min_score' => 0, 'max_score' => 74.99, 'points' => null]]);
        $this->raw($this->id['junior'], 40, 35);
        $this->raw($this->id['primary'], 40, 35);
    }

    private function raw(string $learner, float $first, float $second): void
    {
        foreach ([[$this->id['paper_1'], $first], [$this->id['paper_2'], $second]] as [$paper,$marks]) {
            DB::table('exam_results')->insert(['id' => (string) Str::uuid(), 'exam_id' => $this->id['exam'], 'learner_id' => $learner, 'learning_area_id' => $this->id['area'], 'paper_id' => $paper, 'marks' => $marks, 'is_deleted' => false]);
        }
    }

    private function process(string $learner)
    {
        return app(ResultProcessingService::class)->process($this->id['school'], $this->id['exam_area'], $learner, $this->id['user']);
    }

    public function test_it_aggregates_two_papers_and_applies_junior_grade_and_points(): void
    {
        $r = $this->process($this->id['junior']);
        $this->assertSame('75.00', $r->marks_obtained);
        $this->assertSame('100.00', $r->maximum_marks);
        $this->assertSame('75.00', $r->percentage);
        $this->assertSame('EE2', $r->gradingScale->grade_code);
        $this->assertSame(7, $r->gradingScale->points);
    }

    public function test_primary_grade_has_null_points(): void
    {
        $r = $this->process($this->id['primary']);
        $this->assertSame('EE', $r->gradingScale->grade_code);
        $this->assertNull($r->gradingScale->points);
    }

    public function test_missing_paper_result_is_rejected(): void
    {
        DB::table('exam_results')->where('learner_id', $this->id['junior'])->where('paper_id', $this->id['paper_2'])->delete();
        $this->expectException(ValidationException::class);
        $this->process($this->id['junior']);
    }

    public function test_cross_school_is_rejected(): void
    {
        DB::table('learners')->where('id', $this->id['junior'])->update(['school_id' => $this->id['other_school']]);
        $this->expectException(ValidationException::class);
        $this->process($this->id['junior']);
    }

    public function test_inconsistent_total_marks_is_rejected(): void
    {
        DB::table('exam_learning_areas')->update(['total_marks' => 90]);
        $this->expectException(ValidationException::class);
        $this->process($this->id['junior']);
    }

    public function test_processing_upserts_the_same_result(): void
    {
        $first = $this->process($this->id['junior']);
        DB::table('exam_results')->where('learner_id', $this->id['junior'])->where('paper_id', $this->id['paper_1'])->update(['marks' => 45]);
        $second = $this->process($this->id['junior']);
        $this->assertSame($first->id, $second->id);
        $this->assertSame('80.00', $second->percentage);
        $this->assertDatabaseCount('learning_area_results', 1);
    }

    public function test_api_response_contains_grade_and_points(): void
    {
        $this->withoutMiddleware();
        $user = new User;
        $user->forceFill(['id' => $this->id['user'], 'school_id' => $this->id['school']]);
        Auth::setUser($user);
        $this->postJson('/api/learning-area-results/process', ['school_id' => $this->id['school'], 'exam_learning_area_id' => $this->id['exam_area'], 'learner_id' => $this->id['junior']])->assertOk()->assertJsonPath('data.grade_code', 'EE2')->assertJsonPath('data.points', 7);
    }
}
