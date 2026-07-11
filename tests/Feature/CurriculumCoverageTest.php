<?php

namespace Tests\Feature;

use App\Services\Teaching\CurriculumCoverageService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class CurriculumCoverageTest extends TestCase
{
    private array $id;

    protected function setUp(): void
    {
        parent::setUp();
        $this->schema();
        foreach (['school', 'area', 'grade', 'year', 'term', 'assignment', 'scheme', 'week', 'lesson', 'plan', 'record'] as $n) {
            $this->id[$n] = (string) Str::uuid();
        }DB::table('teacher_assignments')->insert(['id' => $this->id['assignment'], 'school_id' => $this->id['school'], 'learning_area_id' => $this->id['area'], 'grade_id' => $this->id['grade'], 'academic_year_id' => $this->id['year'], 'term_id' => $this->id['term'], 'is_deleted' => false]);
        DB::table('schemes_of_work')->insert(['id' => $this->id['scheme'], 'school_id' => $this->id['school'], 'learning_area_id' => $this->id['area'], 'grade_id' => $this->id['grade'], 'academic_year_id' => $this->id['year'], 'term_id' => $this->id['term'], 'is_deleted' => false]);
        DB::table('academic_weeks')->insert(['id' => $this->id['week'], 'academic_year_id' => $this->id['year'], 'term_id' => $this->id['term'], 'week_number' => 3]);
        DB::table('scheme_lessons')->insert(['id' => $this->id['lesson'], 'scheme_id' => $this->id['scheme'], 'week_id' => $this->id['week'], 'strand' => 'Numbers', 'sub_strand' => 'Fractions', 'is_deleted' => false]);
        DB::table('lesson_plans')->insert(['id' => $this->id['plan'], 'teacher_assignment_id' => $this->id['assignment'], 'scheme_lesson_id' => $this->id['lesson'], 'is_deleted' => false]);
        DB::table('records_of_work')->insert(['id' => $this->id['record'], 'school_id' => $this->id['school'], 'lesson_plan_id' => $this->id['plan'], 'date_taught' => '2026-07-10', 'status' => 'completed', 'is_deleted' => false]);
    }

    public function test_it_derives_coverage_from_the_record_chain(): void
    {
        $c = app(CurriculumCoverageService::class)->create($this->id['record'], $this->id['school']);
        $this->assertSame($this->id['assignment'], $c->teacher_assignment_id);
        $this->assertSame(3, $c->week_number);
        $this->assertTrue($c->completed);
    }

    public function test_it_rejects_an_inconsistent_chain(): void
    {
        DB::table('schemes_of_work')->where('id', $this->id['scheme'])->update(['grade_id' => (string) Str::uuid()]);
        $this->expectException(ValidationException::class);
        app(CurriculumCoverageService::class)->create($this->id['record'], $this->id['school']);
    }

    public function test_it_rejects_duplicate_coverage(): void
    {
        $s = app(CurriculumCoverageService::class);
        $s->create($this->id['record'], $this->id['school']);
        $this->expectException(ValidationException::class);
        $s->create($this->id['record'], $this->id['school']);
    }

    private function schema(): void
    {
        foreach (['curriculum_coverage', 'records_of_work', 'lesson_plans', 'scheme_lessons', 'academic_weeks', 'schemes_of_work', 'teacher_assignments'] as $t) {
            Schema::dropIfExists($t);
        }Schema::create('teacher_assignments', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('school_id');
            $t->uuid('learning_area_id');
            $t->uuid('grade_id');
            $t->uuid('academic_year_id');
            $t->uuid('term_id');
            $t->boolean('is_deleted');
        });
        Schema::create('schemes_of_work', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('school_id');
            $t->uuid('learning_area_id');
            $t->uuid('grade_id');
            $t->uuid('academic_year_id');
            $t->uuid('term_id');
            $t->boolean('is_deleted');
        });
        Schema::create('academic_weeks', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('academic_year_id');
            $t->uuid('term_id');
            $t->integer('week_number');
        });
        Schema::create('scheme_lessons', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('scheme_id');
            $t->uuid('week_id');
            $t->string('strand');
            $t->string('sub_strand');
            $t->boolean('is_deleted');
        });
        Schema::create('lesson_plans', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('teacher_assignment_id');
            $t->uuid('scheme_lesson_id');
            $t->boolean('is_deleted');
        });
        Schema::create('records_of_work', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('school_id');
            $t->uuid('lesson_plan_id');
            $t->date('date_taught');
            $t->string('status');
            $t->boolean('is_deleted');
        });
        Schema::create('curriculum_coverage', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('school_id');
            $t->uuid('teacher_assignment_id');
            $t->uuid('scheme_id');
            $t->uuid('scheme_lesson_id');
            $t->uuid('record_of_work_id')->unique();
            $t->date('date_completed');
            $t->string('strand');
            $t->string('sub_strand');
            $t->integer('week_number');
            $t->boolean('completed');
            $t->boolean('is_deleted');
            $t->timestamp('created_at')->nullable();
            $t->timestamp('deleted_at')->nullable();
            $t->uuid('deleted_by')->nullable();
        });
    }
}
