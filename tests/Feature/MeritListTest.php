<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Assessment\MeritListService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class MeritListTest extends TestCase
{
    private array $id = [];

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['merit_lists', 'learning_area_results', 'grading_scales', 'grading_systems', 'learners', 'streams', 'grades', 'education_levels', 'exams', 'users', 'schools'] as $table) {
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
            $t->boolean('is_deleted');
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
            $t->string('grade_description')->nullable();
            $t->decimal('min_score', 5, 2);
            $t->decimal('max_score', 5, 2);
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
            $t->boolean('is_deleted');
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
            $t->string('ranking_method');
            $t->string('status');
            $t->uuid('generated_by');
            $t->timestamp('generated_at');
            $t->timestamp('published_at')->nullable();
            $t->timestamps();
            $t->boolean('is_deleted');
            $t->timestamp('deleted_at')->nullable();
            $t->uuid('deleted_by')->nullable();
            $t->unique(['school_id', 'exam_id', 'learner_id']);
        });
        foreach (['school', 'other', 'user', 'level', 'grade', 'grade2', 'stream1', 'stream2', 'stream3', 'exam', 'exam_other', 'system', 'scale_high', 'scale_mid'] as $key) {
            $this->id[$key] = (string) Str::uuid();
        }
        foreach (['a', 'b', 'c', 'd'] as $key) {
            $this->id[$key] = (string) Str::uuid();
        }
        DB::table('schools')->insert([['id' => $this->id['school']], ['id' => $this->id['other']]]);
        DB::table('users')->insert(['id' => $this->id['user'], 'school_id' => $this->id['school']]);
        DB::table('education_levels')->insert(['id' => $this->id['level'], 'school_id' => $this->id['school']]);
        DB::table('grades')->insert([['id' => $this->id['grade'], 'school_id' => $this->id['school'], 'education_level_id' => $this->id['level']], ['id' => $this->id['grade2'], 'school_id' => $this->id['school'], 'education_level_id' => $this->id['level']]]);
        DB::table('streams')->insert([['id' => $this->id['stream1'], 'school_id' => $this->id['school'], 'grade_id' => $this->id['grade']], ['id' => $this->id['stream2'], 'school_id' => $this->id['school'], 'grade_id' => $this->id['grade']], ['id' => $this->id['stream3'], 'school_id' => $this->id['school'], 'grade_id' => $this->id['grade2']]]);
        DB::table('learners')->insert([['id' => $this->id['a'], 'school_id' => $this->id['school'], 'grade_id' => $this->id['grade'], 'stream_id' => $this->id['stream1'], 'active' => 1, 'is_deleted' => 0], ['id' => $this->id['b'], 'school_id' => $this->id['school'], 'grade_id' => $this->id['grade'], 'stream_id' => $this->id['stream1'], 'active' => 1, 'is_deleted' => 0], ['id' => $this->id['c'], 'school_id' => $this->id['school'], 'grade_id' => $this->id['grade'], 'stream_id' => $this->id['stream2'], 'active' => 1, 'is_deleted' => 0], ['id' => $this->id['d'], 'school_id' => $this->id['school'], 'grade_id' => $this->id['grade2'], 'stream_id' => $this->id['stream3'], 'active' => 1, 'is_deleted' => 0]]);
        DB::table('exams')->insert([['id' => $this->id['exam'], 'school_id' => $this->id['school'], 'is_deleted' => 0], ['id' => $this->id['exam_other'], 'school_id' => $this->id['other'], 'is_deleted' => 0]]);
        DB::table('grading_systems')->insert(['id' => $this->id['system'], 'school_id' => $this->id['school'], 'education_level_id' => $this->id['level'], 'grading_name' => 'Junior', 'uses_points' => 1, 'uses_marks' => 1, 'active' => 1]);
        DB::table('grading_scales')->insert([['id' => $this->id['scale_high'], 'grading_system_id' => $this->id['system'], 'grade_code' => 'EE', 'grade_description' => 'Exceeding', 'min_score' => 75, 'max_score' => 100, 'points' => 8], ['id' => $this->id['scale_mid'], 'grading_system_id' => $this->id['system'], 'grade_code' => 'ME', 'grade_description' => 'Meeting', 'min_score' => 0, 'max_score' => 74.99, 'points' => 6]]);
        $this->results('a', 90, 8);
        $this->results('b', 80, 8);
        $this->results('c', 80, 8);
        $this->results('d', 70, 6);
    }

    private function results(string $learner, int $percentage, int $points): void
    {
        for ($i = 1; $i <= 2; $i++) {
            DB::table('learning_area_results')->insert(['id' => (string) Str::uuid(), 'school_id' => $this->id['school'], 'exam_id' => $this->id['exam'], 'learner_id' => $this->id[$learner], 'learning_area_id' => (string) Str::uuid(), 'marks_obtained' => $percentage, 'maximum_marks' => 100, 'percentage' => $percentage, 'grading_system_id' => $this->id['system'], 'grading_scale_id' => $points === 8 ? $this->id['scale_high'] : $this->id['scale_mid'], 'processing_status' => 'processed', 'is_deleted' => 0]);
        }
    }

    private function generate(?string $grade = null, ?string $stream = null)
    {
        return app(MeritListService::class)->generate($this->id['school'], $this->id['exam'], $grade, $stream, $this->id['user']);
    }

    public function test_totals_averages_junior_grade_and_points(): void
    {
        $row = $this->generate()->firstWhere('learner_id', $this->id['a']);
        $this->assertSame('180.00', $row->total_score);
        $this->assertSame('200.00', $row->maximum_marks);
        $this->assertSame('90.00', $row->average_percentage);
        $this->assertSame(16, $row->total_points);
        $this->assertSame('EE', $row->overallGradingScale->grade_code);
    }

    public function test_competition_ties_and_all_positions(): void
    {
        $rows = $this->generate()->keyBy('learner_id');
        $this->assertSame([1, 2, 2, 4], [$rows[$this->id['a']]->school_position, $rows[$this->id['b']]->school_position, $rows[$this->id['c']]->school_position, $rows[$this->id['d']]->school_position]);
        $this->assertSame(1, $rows[$this->id['c']]->stream_position);
        $this->assertSame(2, $rows[$this->id['c']]->grade_position);
    }

    public function test_grade_and_stream_filters(): void
    {
        $this->assertCount(3, $this->generate($this->id['grade']));
        DB::table('merit_lists')->delete();
        $this->assertCount(2, $this->generate(null, $this->id['stream1']));
    }

    public function test_cross_school_exam_is_rejected(): void
    {
        $this->expectException(ValidationException::class);
        app(MeritListService::class)->generate($this->id['school'], $this->id['exam_other'], null, null, $this->id['user']);
    }

    public function test_no_processed_results_is_rejected(): void
    {
        DB::table('learning_area_results')->delete();
        $this->expectException(ValidationException::class);
        $this->generate();
    }

    public function test_generation_upserts_rows(): void
    {
        $first = $this->generate()->first()->id;
        $this->generate();
        $this->assertSame($first, DB::table('merit_lists')->where('id', $first)->value('id'));
        $this->assertDatabaseCount('merit_lists', 4);
    }

    public function test_publishing_updates_matching_rows(): void
    {
        $this->generate($this->id['grade']);
        $rows = app(MeritListService::class)->publish($this->id['school'], $this->id['exam'], $this->id['grade'], null);
        $this->assertCount(3, $rows);
        $this->assertTrue($rows->every(fn ($row) => $row->status === 'published' && $row->published_at !== null));
    }

    public function test_api_resource_exposes_rank_and_grade_output(): void
    {
        $this->withoutMiddleware();
        $user = new User;
        $user->forceFill(['id' => $this->id['user'], 'school_id' => $this->id['school']]);
        Auth::setUser($user);
        $this->postJson('/api/merit-lists/generate', ['school_id' => $this->id['school'], 'exam_id' => $this->id['exam'], 'stream_id' => $this->id['stream1']])->assertOk()->assertJsonPath('data.0.overall_grade', 'EE')->assertJsonPath('data.0.overall_points', 8)->assertJsonPath('data.0.stream_position', 1);
    }
}
